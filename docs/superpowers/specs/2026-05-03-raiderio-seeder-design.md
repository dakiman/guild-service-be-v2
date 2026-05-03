# Raider.IO Seeder — Design

**Date:** 2026-05-03
**Status:** Approved
**Phases:** Guilds → Runs → Characters (each shipped independently)

## Problem

The app is not in production yet. The DB is empty except for hand-tested characters (`melaniya@the-maelstrom`, etc.). To stress-test the cascading sync pipeline (character → guild auto-discover, guild → roster fan-out, character → teammate crawl) and to bootstrap a realistic dataset, we need a discovery layer that pulls top characters / guilds / M+ runs from raider.io and feeds them into the existing Blizzard sync jobs. The Blizzard pipeline does the actual data work; raider.io is purely a *discovery channel*.

## Goals

- Bootstrap the database from raider.io top-lists with a single artisan command.
- Reuse the existing `SyncGuildData` and `SyncCharacterData` jobs end-to-end — no parallel sync infrastructure.
- Phase the implementation: ship Guilds first, then Runs, then Characters. Each phase is independently mergeable.
- Stay within rate-limit budgets on both sides (raider.io 300/min public, Blizzard 80/s + 30k/hr).
- Memory-efficient enough to run on the user's home server (~10-15 MB resident).
- Deduplicate intelligently so re-running the seeder doesn't re-queue fresh data.
- Document the seeder in `backend/CLAUDE.md` so future Claude sessions can use and extend it without rediscovery.

## Non-goals (phase 1)

- No scheduled / cron-driven seeding. Manual artisan only.
- No public API surface, no FE UI. The "manual re-sync button" is a future plan.
- No raider.io data persisted beyond the run-dedupe ledger. We do not store raider.io scores, attendance, or any DTO fields.
- No promotion to a full module (`app/RaiderIO/`). Lean service today; promote later if usage expands beyond discovery.
- No retroactive cleanup of `seeded_runs` rows. Schema leaves room (`seeded_at` index) for a future pruning job.

## Architecture

### Layout

```
backend/app/
├── Services/RaiderIO/
│   ├── RaiderIOClient.php          # Guzzle wrapper; in-app 250/min throttle; 429 backoff
│   ├── RaiderIOSeeder.php          # Orchestrator; one method per phase
│   ├── DTO/
│   │   ├── SeedGuildRef.php        # readonly: region, realm_slug, name
│   │   ├── SeedRunRef.php          # readonly: keystone_run_id, dungeon_id, region, members[]
│   │   └── SeedCharacterRef.php    # readonly: region, realm_slug, name
│   └── Exceptions/
│       └── RaiderIOException.php
├── Console/Commands/
│   └── RaiderIOSeed.php            # php artisan raiderio:seed --phase=… --limit=N --regions=…
└── Models/
    └── SeededRun.php               # tiny new table for run-dedupe (keystone_run_id PK)

backend/config/
└── raiderio.php                    # base_url, throttle, ttl, regions, per-phase limits, crawl flag

backend/database/migrations/
└── YYYY_MM_DD_create_seeded_runs_table.php
```

### Why a lean service (not a full module)

raider.io is a *throwaway discovery channel*. We pull lists, dispatch existing Blizzard jobs, then never touch raider.io again for that data. The Blizzard module is heavy because Blizzard data is the project's primary domain — cached, transformed, persisted, exposed. raider.io DTOs never leak past `RaiderIOSeeder`; nothing raider.io-shaped is persisted except `seeded_runs.keystone_run_id`. A 250-line service + one command + one rate-limited HTTP client is right-sized.

**Future requirement (not phase 1):** if raider.io usage expands beyond discovery (e.g. consuming raider.io's score breakdowns, guild attendance, alt-tracking), promote `app/Services/RaiderIO/` to a full `app/RaiderIO/` module mirroring `app/Blizzard/` (Client, DTO, Mapper, Jobs, Middleware, ServiceProvider). Today's lean shape is right *only* because raider.io is discovery-only.

### Why a separate `seeded_runs` table

raider.io's `keystone_run_id` is *its* identifier, not Blizzard's. The existing `dungeon_runs` table comes from Blizzard's M+ profile endpoint, with a different identity model (completion timestamp + roster). Mixing the two on one table would muddy the schema. Separate table = clean dedupe namespace, easy to drop if we ever stop seeding.

```sql
CREATE TABLE seeded_runs (
    keystone_run_id BIGINT PRIMARY KEY,    -- raider.io's id
    region          VARCHAR(2)  NOT NULL,
    seeded_at       TIMESTAMP   NOT NULL DEFAULT now()
);
CREATE INDEX seeded_runs_seeded_at_idx ON seeded_runs (seeded_at);  -- for future cleanup/expiry
```

No FK to anything. Pure dedupe ledger.

### One existing-code modification

`SyncGuildRoster` today creates `guild_members` shells but does *not* dispatch `SyncCharacterData` per member. We add an opt-in fan-out gated by a config flag (`raiderio.dispatch_roster_character_syncs` — default `true` since it's the seeder's whole point, but the flag exists so the existing tier-2 guild proactive sync doesn't accidentally cascade). Same TTL gate applies per member.

## Configuration

`config/raiderio.php`:

```php
return [
    'base_url' => env('RAIDERIO_BASE_URL', 'https://raider.io/api/v1'),
    'throttle' => [
        'per_minute' => env('RAIDERIO_RATE_PER_MINUTE', 250),  // headroom under 300/min ceiling
    ],
    'regions' => array_filter(explode(',', env('RAIDERIO_SEED_REGIONS', 'eu,us'))),
    'season' => env('RAIDERIO_SEED_SEASON', 'season-mn-1'),
    'phase' => [
        'guilds_per_region'     => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
        'runs_pages_per_region' => (int) env('RAIDERIO_SEED_RUNS_PAGES_PER_REGION', 5),
        'chars_per_spec'        => (int) env('RAIDERIO_SEED_CHARS_PER_SPEC', 20),
    ],
    'character_resync_ttl' => (int) env('RAIDERIO_SEED_CHAR_TTL', 12 * 3600),
    'teammate_crawl_during_seed' => (bool) env('RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED', false),
    'dispatch_chunk_size' => (int) env('RAIDERIO_SEED_CHUNK', 50),
    'dispatch_roster_character_syncs' => (bool) env('RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS', true),
];
```

## Components

### `RaiderIOClient`

Thin Guzzle/`Http::` wrapper. Public methods scoped tightly to discovery:

- `topGuilds(string $region, int $limit): iterable<SeedGuildRef>` — paginates `/guilds/static-raid-rankings?raid={current}&difficulty=mythic&region={r}&limit=20&page=N` until `$limit` reached.
- `topRuns(string $region, string $season, int $pages): iterable<SeedRunRef>` — paginates `/mythic-plus/runs?season={s}&region={r}&page=N`.
- `topCharactersBySpec(string $region, string $class, string $spec, int $limit): iterable<SeedCharacterRef>` — pulls from `/mythic-plus/leaderboards`.

Each method is a generator (`yield` per row) — the seeder iterates lazily so we never hold a 1,000-guild collection in memory.

In-app throttle: Redis token bucket (`Redis::throttle`-based), same idea as `BlizzardRateLimiter` but client-side since we control the dispatch loop. On HTTP 429, sleep `Retry-After` (default 60s if absent), retry once; second 429 raises `RaiderIOException` and the caller logs + skips that page.

### `RaiderIOSeeder`

Three public methods, one per phase:

- `seedGuilds(SeedOptions $opts): SeedReport`
- `seedRuns(SeedOptions $opts): SeedReport`
- `seedCharacters(SeedOptions $opts): SeedReport`

`SeedOptions` carries CLI overrides: `regions`, `limit`, `force`, `dryRun`. `SeedReport` is a readonly DTO: `{phase, regions, considered, dispatched, skipped_ttl, skipped_dedupe, errors}`. Returned by the command for log output.

### `RaiderIOSeed` artisan command

```bash
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu
php artisan raiderio:seed --phase=runs
php artisan raiderio:seed --phase=characters
php artisan raiderio:seed --phase=all              # runs guilds → runs → characters in order
```

Flags:
- `--phase=guilds|runs|characters|all` (required)
- `--limit=N` overrides phase-specific config limit
- `--regions=eu,us` overrides config
- `--force` bypasses TTL gate (for the future "manual re-sync" UI hook)
- `--dry-run` skips dispatches; reports what *would* happen (used for memory-budget verification on the home server)

CLI flags override config but never persist. Prints `SeedReport` table at exit.

## Data flow per phase

### Phase 1 — Guilds (`--phase=guilds`)

```
artisan raiderio:seed --phase=guilds --limit=10
  └─> RaiderIOSeeder::seedGuilds()
        for region in [eu, us]:
          for guildRef in client.topGuilds(region, 10):       # 10 raider.io calls/region
            if Guild::byIdentity(...).isRosterStale() == false:
                skipped_ttl++; continue
            SyncGuildData::dispatch(region, realmSlug, name)  # → blizzard-roster-sync queue
            dispatched++
          flush log line every config.dispatch_chunk_size

# Then queue cascades autonomously, paced by BlizzardRateLimiter:
SyncGuildData (per guild)
  └─> writes Guild + dispatches SyncGuildRoster
        └─> writes guild_members rows
              └─> [NEW] for each member:
                    if Character::byIdentity(...).updated_at fresher than TTL: skip
                    else SyncCharacterData::dispatch(..., depth: Full)
                          └─> [existing] auto-creates+dispatches SyncGuildData for any *new* guild encountered
                          └─> [existing, gated] dispatches teammate-crawl Full syncs
```

### Phase 2 — Runs (`--phase=runs`)

```
RaiderIOSeeder::seedRuns()
  for region in regions:
    for page in 1..config.runs_pages_per_region:
      response = client.topRuns(region, season, page)         # 1 raider.io call/page
      for runRef in response:
        $inserted = DB::table('seeded_runs')->insertOrIgnore([
          'keystone_run_id' => $runRef->keystoneRunId,
          'region' => $region, 'seeded_at' => now(),
        ]);
        if ($inserted === 0): skipped_dedupe++; continue
        for memberRef in runRef.members:                       # ~5 members per run
          if Character::byIdentity(...).updated_at fresher than TTL: skipped_ttl++; continue
          SyncCharacterData::dispatch(..., depth: Full)
          dispatched++
```

We do not persist anything about the run itself (dungeon, score, time) — that data flows in naturally via `SyncCharacterData`'s mythic+ slice when each member syncs. raider.io is purely the discovery channel.

### Phase 3 — Characters (`--phase=characters`)

```
RaiderIOSeeder::seedCharacters()
  for region in regions:
    for class in WOW_CLASSES:                                  # 13 classes
      for spec in class.specs:                                 # 3-4 specs each
        for charRef in client.topCharactersBySpec(region, class, spec, config.chars_per_spec):
          # ~13 × 3.3 × 20 = ~860 chars/region; 2 regions = ~1,700 chars
          # raider.io call budget: ~13 × 3.3 × 2 = ~86 calls (well under 300/min)
          if Character::byIdentity(...).updated_at fresher than TTL: skipped_ttl++; continue
          SyncCharacterData::dispatch(..., depth: Full)
          dispatched++
```

### Phase: `all`

Runs phases 1 → 2 → 3 sequentially in one process. Phases are independent: phase 2 doesn't wait for phase 1's queue to drain. The seeder is a *dispatcher*, not a synchronizer.

## Dedupe model

| Resource | Dedupe key | Mechanism | Reset/force? |
|---|---|---|---|
| Character | `(region, realm, name)` | `Character.updated_at` + 12h TTL | `--force` flag |
| Run | `keystone_run_id` | `seeded_runs` ledger, permanent | none — runs are immutable |
| Guild | `(region, realm, name)` | existing `Guild.isRosterStale()` (config/blizzard.php threshold) | `--force` flag |

### Characters: TTL gate at dispatch time

The check happens **before** `SyncCharacterData::dispatch()`, not at job-execution time. Reason: `SyncCharacterData` already has `ShouldBeUnique` (60s window keyed on `region:realm:name:depth`), but that only dedupes *bursts*. A character synced 4 hours ago is fresh from the seeder's perspective but past the 60s unique window — without a TTL gate, we'd needlessly re-queue every member of every top guild on every seed run.

```php
$existing = Character::byIdentity($name, $realmSlug, $region)->first();
if ($existing && $existing->updated_at?->isAfter(now()->subSeconds(config('raiderio.character_resync_ttl')))) {
    $report->skippedTtl++;
    continue;
}
SyncCharacterData::dispatch($region, $realmSlug, $name, SyncDepth::Full);
```

- TTL default 12h (env: `RAIDERIO_SEED_CHAR_TTL=43200`).
- Reads `Character.updated_at` — the same column `Character::isStale()` and the existing teammate-crawl recency check consult. There is no top-level `synced_at` column; per-slice columns (`mythics_synced_at`, etc.) exist but are not the right granularity for "did the seeder hit this character recently."
- `--force` flag bypasses; reserved for the future "manual re-sync" UI button.

### Runs: identity-hash via ledger

raider.io exposes a stable `keystone_run_id` (bigint) per run. Used directly — no hashing needed; it *is* the identity. `insertOrIgnore` makes the seed run race-safe across regions returning the same run.

No TTL on `seeded_runs` — runs are immutable historical events; once seen, never re-seed. Table has `seeded_at` index so a future cleanup job can prune rows older than e.g. 90 days.

### Guilds: reuse existing staleness

No new dedupe table. The seeder defers to `Guild::isRosterStale()`, which reads `config/blizzard.php` thresholds.

## Error handling

raider.io discovery failures are **non-fatal to the seed run**. The seeder's contract is best-effort: pull what it can, dispatch what it can, log the rest.

| Failure | Where | Handling |
|---|---|---|
| HTTP 429 from raider.io | `RaiderIOClient::request()` | Sleep `Retry-After` (default 60s), retry once. Second 429 → throw `RaiderIOException`. |
| HTTP 5xx / timeout | `RaiderIOClient::request()` | Exponential backoff retry, max 3 attempts (1s, 4s, 10s). Final failure → throw. |
| HTTP 4xx (non-429) | `RaiderIOClient::request()` | No retry. Throw immediately. |
| HTTP 401/403 | `RaiderIOClient::request()` | Fail fast — auth misconfig, abort whole phase. |
| `RaiderIOException` mid-pagination | `RaiderIOSeeder::seedX()` | Catch per-page. Log `{phase, region, page, error}`. Increment `report.errors`. **Continue with next page.** |
| Malformed/missing fields in raider.io response | DTO factory | Skip that row. Increment `report.errors`. Continue iteration. |
| `dispatch()` throws | seeder dispatch loop | Should not happen in practice. If it does, log + skip + continue. |
| Cascaded job failures | downstream Blizzard jobs | **Out of scope.** Existing retry/failed-job machinery handles them. Seeder is fire-and-forget. |

The seeder never aborts a phase mid-flight unless raider.io returns 401/403. All other failures are isolated to the row/page that triggered them.

## Rate limiting

### raider.io side (client-side throttle)

Redis token bucket, scoped per-process:

```php
Redis::throttle('raiderio:requests')
    ->allow(config('raiderio.throttle.per_minute'))   // 250
    ->every(60)
    ->block(30)                                        // wait up to 30s for a slot
    ->then(fn () => Http::get($url));
```

- Default 250/min — 17% headroom under the 300/min public ceiling.
- `block(30)` waits up to 30s for a slot before throwing.
- 429 with `Retry-After` still triggers per-call sleep on top — belt-and-suspenders.
- Bucket key is global (`raiderio:requests`), not per-region. Concurrent seed phases share the budget — correct behavior.

### Blizzard side

Nothing new. Existing `BlizzardRateLimiter` middleware on `SyncCharacterData` / `SyncGuildData` does the work. The seeder may dispatch 10,000 jobs in 30 seconds; the middleware paces actual Blizzard API calls at 80/s, 30k/hr ceiling. ~1,500 Full character syncs/hour wall-clock.

`php artisan horizon` must be running before the seeder is fired; queue depth balloons during a seed and Horizon drains it.

## Logging

Existing `Log::channel('blizzard')` channel — no new channel.

```
[INFO] raiderio.seed.start phase=guilds regions=eu,us limit=10
[INFO] raiderio.seed.dispatched phase=guilds region=eu realm=tarren-mill name=Echo dispatched=1
[INFO] raiderio.seed.skipped_ttl phase=guilds region=eu realm=... reason=fresh_updated_at
[WARN] raiderio.seed.error phase=runs region=us page=7 error=...
[INFO] raiderio.seed.complete phase=guilds considered=20 dispatched=18 skipped_ttl=2 errors=0
```

Structured fields for grep-friendliness. `Log::warning` for per-row errors, `Log::error` only for catastrophic abort cases (auth misconfig).

## Memory profile

Generator-based pagination → at most one raider.io page (~20 rows) in memory at a time. `dispatch_chunk_size=50` batches log output and triggers PHP gc between chunks. Total resident memory during a phase: ~10-15 MB (Laravel boot + Guzzle + small DTO buffer). Redis queue depth scales with dispatched job count — Horizon's problem, not the seeder's.

## Testing

### Unit tests (`tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`)

- TTL gate skips fresh characters (`Queue::fake()` + `assertNothingPushed`).
- TTL gate dispatches stale characters.
- TTL gate dispatches missing characters.
- `--force` bypasses TTL.
- Run dedupe via `seeded_runs`: first call inserts and dispatches; second call same `keystone_run_id` returns 0 inserts, no dispatches.
- Guild roster-staleness gate (one test, doesn't re-test `isRosterStale()`).
- Per-page error isolation: stub client to throw on page 3; pages 1, 2, 4, 5 still dispatched; `report.errors == 1`.
- Region scoping: config `regions=eu` → no `region=us` dispatches.
- Chunked dispatch logging boundary.

### Integration tests (`tests/Feature/RaiderIO/RaiderIOSeedCommandTest.php`)

`Http::fake()` to mock raider.io. Hand-curated JSON fixtures under `tests/fixtures/raiderio/`:
- `top-guilds-eu.json` (3 guilds)
- `top-runs-eu.json` (5 runs)
- `top-chars-deathknight-blood-eu.json` (3 chars)

- `--phase=guilds` end-to-end: 3× `SyncGuildData` queued.
- `--phase=runs` dispatches member syncs: 5 runs × 5 members = 25 char dispatches; `seeded_runs` has 5 rows.
- `--dry-run` dispatches nothing but logs everything; report counts match a real run.
- `--phase=all` runs phases sequentially; verify dispatch counts for all three.
- 429 backoff path: fake returns 429 with `Retry-After: 1` once, then 200; assert one retry.
- HTTP 500 retries: 502/502/502/200 — current page skipped with logged error, next page succeeds.

### Out of test scope

- The cascade itself (`SyncCharacterData` → roster → teammate crawl) — already covered by existing tests.
- Blizzard rate limiter — existing.
- raider.io DTO field shapes against live API — brittle. Fixtures are good enough.
- Memory profile / stream-based pagination — verified manually on the home-server during the first 10-guild run.

### CI considerations

- SQLite in-memory + `Queue::fake()` per project convention.
- One real-Redis test (429 throttle backoff) opt-in via `@group integration`; runs in the existing Docker test container.

### Manual verification checklist (post-implementation)

1. `--dry-run` against `eu` with `limit=10` — verify log output, no DB writes.
2. Real run `eu limit=10` — observe Horizon dashboard for queue depth, watch home-server memory (`docker stats` for `app` container).
3. Wait 1 hour, check `Character` count in DB (~50-300 depending on guild sizes).
4. Re-run same command — verify `report.skipped_ttl` matches dispatched count from step 2 (no double work).

## Documentation deliverables

### 1. `backend/CLAUDE.md` — primary doc for future Claude sessions

New section after "Blizzard Module," matching the existing dense-bullet style. Covers: architecture, trigger, phases, dedupe model, roster fan-out, teammate crawl, rate limits, memory profile, future-promotion requirement, env vars, common invocations.

### 2. Cross-repo `CLAUDE.md` (project root) — one-line addition

```markdown
- **RaiderIO seeder.** `php artisan raiderio:seed` (in `backend/`) bootstraps
  characters/guilds/runs from raider.io top-lists. Discovery-only — see
  `backend/CLAUDE.md` "RaiderIO Seeder" section for full details. Default
  regions `eu,us`, default season `season-mn-1`.
```

### 3. `backend/.env.example` — env var stubs

All nine `RAIDERIO_*` vars added with default values commented inline, grouped under a `# raider.io seeder` heading. Same pattern as existing `BLIZZARD_*` vars.

## Out of scope for documentation (phase 1)

- No README in `app/Services/RaiderIO/` — the CLAUDE.md section is the README.
- No public API docs / Swagger entry — no public API surface.
- No FE-side documentation — no UI in phase 1.

## Open items / future plans

- **Manual re-sync UI button.** Future feature, not phase 1. Will call `php artisan raiderio:seed --force ...` under the hood.
- **Scheduled refresh.** One-line `Schedule::command('raiderio:seed --phase=all')->weekly()` add when desired. Deliberately omitted from phase 1 to avoid scheduler/test interference.
- **Pruning `seeded_runs`.** Schema is ready (index on `seeded_at`); pruning job not built. Add when the table grows past ~100k rows.
- **API-key tier.** No public auth tier known for raider.io as of 2026-05-03. If they ever expose one, `RaiderIOClient` adds a header and the throttle ceiling bumps — single-file change.
- **Promotion to full module.** Documented above — triggered by raider.io usage expanding beyond discovery.
