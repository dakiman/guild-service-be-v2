# Recursive Teammate Crawl — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When `SyncCharacterData` runs at `SyncDepth::Full` and persists a character's Mythic+ runs, fan out and dispatch a `SyncDepth::Standard` sync for each teammate found across those runs (capped to a configurable depth, default 1), so the data tree expands outward from a seed character without ever bypassing the rate limiter or circuit breaker.

**Architecture:** Add an opt-in, depth-tracked recursive dispatch step to `SyncCharacterData`. The seed sync completes its M+ slice (writes `mythics_synced_at` and persists all 5 dungeon-run-member pivot rows — see prerequisite below), then a new `dispatchTeammateCrawl()` private method runs at the very end of `handle()` (after all slice writes have already committed). It iterates the just-persisted `dungeon_run_members` rows for the seed's recent runs, deduplicates by Blizzard identity, skips teammates whose `Character` row is fresher than `BLIZZARD_CRAWL_RECENT_THRESHOLD` seconds, and dispatches one `SyncCharacterData` job per remaining teammate at `crawlDepth + 1` and `SyncDepth::Standard` onto the `blizzard-background` queue. The new `crawlDepth` constructor arg defaults to `0`; jobs at `crawlDepth >= BLIZZARD_CRAWL_MAX_DEPTH` skip the fan-out entirely. Whole feature is gated behind `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` (default `false`). The crawled jobs ride the existing `BlizzardRateLimiter` + `BlizzardHealthCheck` middleware and `ShouldBeUnique` (60s) — no new infra.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Redis (Horizon queues + rate-limit throttle), PHPUnit.

**Out of scope:**
- Team-pivot row-clobber bug fix — separate plan: `docs/superpowers/plans/2026-05-01-mythic-plus-team-pivot-fix.md` (PREREQUISITE — see Pre-reading §A).
- Schema changes — no migrations; `Character` model already carries every column needed.
- Frontend work — backend-only.
- Adjusting `BLIZZARD_STALE_CHARACTER_*` defaults — operator change, not in this plan.
- Crawling raid co-attendees or PvP teammates — Mythic+ only for v1 (highest signal, naturally bounded at 5 per run).
- Crawling at `SyncDepth::Full` — would explode fan-out factor (see §6 design rationale below).
- Surfacing crawl-status in API responses or admin UI.

---

## Pre-reading (read before touching code)

### A. Prerequisite: team-pivot row-clobber fix

The crawl is **functionally useless** until the team-pivot bug fixes. Today, `SyncCharacterData::syncMythicPlus()` (lines 277-293) calls:

```php
$dungeonRun->members()->syncWithoutDetaching([
    $memberCharacter?->id ?? $character->id => [
        'character_name' => $member['name'],
        'character_realm' => $member['realm'],
        ...
    ],
]);
```

For every teammate not yet in our `characters` table, the pivot key falls back to the seed's own id, so the same `(dungeon_run_id, $character->id)` pivot row is updated five times in a row — only the LAST teammate's name/realm/spec survives.

**DB verification (run 2026-05-01 against the dev postgres container, `guild_service` DB):**

```sql
SELECT
  COUNT(*) FILTER (WHERE c = 1) AS only_1,
  COUNT(*) FILTER (WHERE c = 2) AS only_2,
  COUNT(*) FILTER (WHERE c = 3) AS only_3,
  COUNT(*) FILTER (WHERE c = 4) AS only_4,
  COUNT(*) FILTER (WHERE c = 5) AS only_5,
  COUNT(*) FILTER (WHERE c > 5) AS more_than_5,
  COUNT(*) AS total_runs,
  ROUND(AVG(c)::numeric, 2) AS avg_members
FROM (
  SELECT dungeon_run_id, COUNT(*) AS c
  FROM dungeon_run_members
  GROUP BY dungeon_run_id
) t;
```

Result:
```
 only_1 | only_2 | only_3 | only_4 | only_5 | more_than_5 | total_runs | avg_members
--------+--------+--------+--------+--------+-------------+------------+-------------
     17 |      3 |      0 |      0 |      0 |           0 |         20 |        1.15
```

17 of 20 runs have only 1 member, 3 have 2, none have ≥3. **No run has the full 5-member roster.** Spot-checking the 2-member runs (e.g., `dungeon_run_id=17`) shows both rows resolve to the **synced characters** themselves (`character_id` = 1 for `melaniya`, `character_id` = 2 for `saiyanin`) — i.e., melaniya synced and wrote one row; saiyanin synced (the same shared run) and wrote another row. Each individual sync wrote exactly **one** pivot row containing the LAST iterated teammate's identity.

The schema's `uq_dungeon_run_member` UNIQUE on `(dungeon_run_id, character_name, character_realm, character_region)` is well-defined; Eloquent just isn't using it because `BelongsToMany::syncWithoutDetaching` keys exclusively on the related model PK.

**Confirms hypothesis exactly.** The crawl in this plan iterates `dungeon_run_members` rows for the seed's runs to find teammates; without the pivot fix, iterating finds at most 1 unique teammate per run instead of 4. That's the dependency.

The pivot-fix plan is being authored in parallel as `docs/superpowers/plans/2026-05-01-mythic-plus-team-pivot-fix.md`. **Do not start this plan's implementation until the pivot fix is merged.** The crawl can technically run against the broken pivot — it will just dispatch 1 extra teammate sync per run instead of 4, defeating the point.

### B. Existing Blizzard module conventions (from `backend/CLAUDE.md`)

- All Blizzard jobs implement `ShouldQueue` + `ShouldBeUnique` (60s) and use `BlizzardRateLimiter` + `BlizzardHealthCheck` middleware. **Do not bypass either.** The rate limiter is the only thing that protects the 80 req/s budget; the health check short-circuits the queue when Blizzard is in an outage.
- Queue priority order in Horizon: `blizzard-auth` > `blizzard-user-sync` > `blizzard-roster-sync` > `blizzard-background`. Crawled teammate jobs land on `blizzard-background` so they cannot starve user-initiated lookups.
- `SyncDepth::Standard` = profile + media + equipment + specs (4 Blizzard requests). `SyncDepth::Full` adds the 9 slices on top (~10 more requests). Crawl uses `Standard` (see §6).
- `ShouldBeUnique` keys on `region:realm:name:depth` for 60s — protects against burst dispatch from concurrent seeds. Insufficient as a longer-term skip; we add an explicit "skip if recently synced" gate on top.
- `Character::byIdentity($name, $realm, $region)` already filters `game_version='retail'` — use it.
- `dungeon_run_members` schema (verified):
  ```
  id, dungeon_run_id, character_id (nullable, FK SET NULL), character_name,
  character_realm, character_region, spec_id, spec_name, equipped_item_level
  ```
  After the pivot fix, every teammate (known or unknown) will have a row keyed by `(dungeon_run_id, character_name, character_realm, character_region)`. The crawl reads `character_name`, `character_realm`, `character_region` from this pivot; `character_id` may be null and is irrelevant for dispatch (we look up / create via the sync job itself).

### C. Worst-case fan-out arithmetic (justifies depth cap = 1)

| crawl depth | runs/seed | teammates/run | unique teammates after dedup | Blizzard requests/teammate (Standard) | total requests |
|---|---|---|---|---|---|
| 0 (seed) | 8 | — | — | 4 (Standard) or ~14 (Full) | 4 / 14 |
| 1 (seed's teammates) | — | 4 (excl. self) | up to 32 (8 × 4) | 4 each | 128 |
| 2 (teammates' teammates) | 8 each | 4 each | up to 1024 (32 × 8 × 4) | 4 each | 4096 |
| 3 | — | — | up to 32k | 4 each | ~130k |

Per the rate-limit budget (80 req/s = 288k/hr — but `per_hour` cap is 30k/hr per `config/blizzard.php`), depth=2 from a single seed would consume ~14% of the hourly budget; depth=3 would blow it apart and starve user-initiated traffic for hours. Even depth=2 is a coordination hazard if multiple seeds are crawling simultaneously.

**Decision: default cap = 1 (seed → seed's direct teammates only).** Operator can raise to 2 via env if dedup proves effective on real data; 3+ is forbidden by code-side validation (see Task 4 Step 3).

### D. Files to read (in this order, before starting Task 1)

1. `backend/app/Blizzard/Jobs/SyncCharacterData.php` (whole file, 695 lines — especially constructor 64-72, `uniqueId()` 74-77, `handle()` 87-241, `syncMythicPlus()` 243-309)
2. `backend/app/Models/Character.php` lines 40-180 (fillables, scopes, staleness helpers)
3. `backend/app/Models/DungeonRun.php` and the `members()` BelongsToMany definition
4. `backend/app/Enums/SyncDepth.php` (3 cases)
5. `backend/config/blizzard.php` lines 30-80 (staleness, sync flags, rate-limit)
6. `backend/CLAUDE.md` — the "Per-slice Full sync" and "Queue Layout" bullets
7. The pivot-fix plan (`2026-05-01-mythic-plus-team-pivot-fix.md`) once it lands — confirm it's merged into the same target branch as this work before touching code

---

## Design decisions (locked before tasks)

These answer the design questions the brainstorm raised. Any deviation in implementation is a plan failure.

1. **Dispatch hook location.** A new private method `dispatchTeammateCrawl()` called from the **end** of `SyncCharacterData::handle()`, after the `if ($this->depth === SyncDepth::Full) { ... }` block. It is **not** dispatched from inside `syncMythicPlus()` because we want the dispatch to be a no-op if M+ failed (no `mythics_synced_at` written → the read of `dungeon_run_members` finds nothing for runs in that season). Crawl is also conceptually orthogonal to a single slice; placing it at the bottom of `handle()` makes it visible at a glance and easy to gate.

2. **Depth tracking.** Add `public readonly int $crawlDepth = 0` to the `SyncCharacterData` constructor. The seed has `crawlDepth=0`; teammates dispatched from a `crawlDepth=N` job get `crawlDepth=N+1`. The crawl method short-circuits if `$this->crawlDepth >= config('blizzard.crawl.max_depth')`.

3. **`uniqueId()` does NOT include `crawlDepth`.** Two jobs for the same `(region,realm,name,depth)` are still functionally identical from Blizzard's API perspective — the only difference is what they do *after* the sync (whether they fan out further). Including `crawlDepth` would let a seed-dispatched depth=0 job and a crawl-dispatched depth=1 job for the same character both run within 60s, doubling Blizzard cost. Better to dedup the API work; if the lower-depth job wins, no further fan-out happens this cycle (acceptable — they'll be re-crawled next time the seed syncs).

4. **What gets crawled.** Mythic+ teammates only. `dungeonRun.members` for the seed's `mythics_synced_at`-bearing runs in the **current season** (filter by `dungeon_runs.season = currentSeason`). Raid co-attendees and PvP teammates are out — the spec calls them out explicitly.

5. **Dedup / skip-if-recent.** Before dispatching for teammate `(name,realm,region)`:
   - Resolve the `Character` row via `Character::byIdentity($name, $realm, $region)->first()`.
   - If row exists and `synced_at IS NOT NULL AND synced_at > now() - BLIZZARD_CRAWL_RECENT_THRESHOLD seconds`, skip.
   - Otherwise dispatch `SyncCharacterData` at `Standard` + `crawlDepth+1` onto `blizzard-background`.

   Default threshold: `BLIZZARD_CRAWL_RECENT_THRESHOLD = 21600` (6h) — same order of magnitude as the longer-cadence slice staleness thresholds; if a teammate was synced within the last 6h via *any* path (user lookup, prior crawl, proactive sync), don't re-spend Blizzard quota on them. The seed's own `mythics_synced_at` is now/recent, so we never re-dispatch the seed itself.

   The crawl also dedupes within its own batch — multiple runs sharing a teammate produce a single dispatch.

6. **Sync depth for crawled chars.** `SyncDepth::Standard`. Reasoning:
   - `Shallow` would persist only the basic profile and skip equipment/specs — too little to make the seed's run rendering useful (FE shows teammate ilvl/spec).
   - `Full` would persist all 9 slices including M+, which would itself trigger another crawl from the teammate (because the M+ slice's pivot rows would let `dispatchTeammateCrawl()` find the teammate's teammates). Even with `crawlDepth >= max` blocking the second hop, `Full` quintuples Blizzard cost per teammate (~14 vs. 4 reqs). Not worth it for an outward-facing tree-walk; `Standard` gives us name + race + class + spec + equipment which is exactly what `MythicPlusRuns.vue` and friends consume.
   - When the user later visits the teammate directly, `CharacterService::getByIdentity()` will see the Standard data is fresh-enough on the profile slice but stale on M+/PvP/etc., return 200 with `X-Data-Staleness`, and dispatch a Full background refresh. So Standard now + Full lazily later is the right shape.

7. **Kill switch.** `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED`, default `false`, lives in `config('blizzard.sync.teammate_crawl_enabled')`. The crawl is brand-new behavior with non-trivial blast radius; per the Plan-4/5 precedent it stays gated **for the foreseeable future** (not just one ramp cycle), because its blast radius is unbounded by the per-character slice contract that the existing Plan-2 flags protect — a runaway crawl could touch thousands of characters before Horizon backpressure kicks in. Future cleanup of this flag is *only* warranted after multi-week prod observation showing crawl-driven dispatch counts are stable and predictable.

8. **Failure independence.** The crawl runs at the end of `handle()` after `mythics_synced_at` and all other `*_synced_at` columns have already been written. Dispatching jobs is just `dispatch()` calls into Redis — synchronous failure modes are exotic (Redis down, queue config wrong); even those don't roll back the seed's already-committed writes. We wrap the crawl method in `try { ... } catch (Throwable $e) { Log::warning(...) }` defense-in-depth so a dispatch hiccup never propagates to mark the seed job as `failed()`. Each crawled job is independent — its own retries, its own circuit-breaker, its own `failed()` log line.

9. **Eloquent vs. raw query for the teammate read.** Use Eloquent: `$character->dungeonRuns()->where('season', $currentSeason)->with('members')->get()->flatMap(...)`. Keeps it readable and the data is already in the DB — no perf concern at depth-1 scale (max 8 runs × 5 rows = 40 rows per seed). If profile shows this is hot, switch to a single `DB::table('dungeon_run_members')->join(...)` later.

10. **Ordering inside `handle()`.** The crawl dispatch is the **last** statement in `handle()`. It runs whether the M+ slice succeeded or failed — if M+ failed there are no fresh members to iterate, so the crawl method returns early naturally. No special ordering against any other slice.

---

## Task 1: Add `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` flag + crawl config keys to `config/blizzard.php`

**Files:**
- Modify: `backend/config/blizzard.php`

- [ ] **Step 1: Confirm baseline state**

Run:
```bash
cd /home/dakiman/projects/guild-service-v2/backend
grep -n "teammate_crawl\|crawl\." config/blizzard.php
```

Expected: no matches.

- [ ] **Step 2: Add the flag to the existing `'sync' => [...]` array**

In `backend/config/blizzard.php`, find this exact block (around lines 75-80):

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
    ],
```

Replace with (one new line at the end):

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'teammate_crawl_enabled' => (bool) env('BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED', false),
    ],
```

- [ ] **Step 3: Add a new top-level `'crawl' => [...]` block immediately after the `'sync' => [...]` block**

After the closing `],` of the `sync` array, insert this new block:

```php
    /*
    |--------------------------------------------------------------------------
    | Teammate Crawl
    |--------------------------------------------------------------------------
    | Recursive fan-out from a Full-sync seed character to its Mythic+
    | teammates. `max_depth` = 0 disables fan-out (only the seed syncs).
    | `max_depth` = 1 dispatches one sync per direct teammate. Higher values
    | are clamped to 2 in the dispatch path; production should not exceed 1.
    | `recent_threshold` (seconds) skips teammates whose `synced_at` is fresher
    | than this window — same scale as the longer slice staleness thresholds.
    */

    'crawl' => [
        'max_depth' => (int) env('BLIZZARD_CRAWL_MAX_DEPTH', 1),
        'recent_threshold' => (int) env('BLIZZARD_CRAWL_RECENT_THRESHOLD', 21600),
    ],
```

- [ ] **Step 4: Sanity-check the file parses**

Run:
```bash
php -l config/blizzard.php
```

Expected: `No syntax errors detected in config/blizzard.php`.

- [ ] **Step 5: Verify config loads correctly**

Run:
```bash
docker compose exec -T app php artisan tinker --execute="dump(config('blizzard.sync.teammate_crawl_enabled')); dump(config('blizzard.crawl.max_depth')); dump(config('blizzard.crawl.recent_threshold'));"
```

Expected output (env defaults):
```
false
1
21600
```

- [ ] **Step 6: Commit**

```bash
git add config/blizzard.php
git commit -m "feat(crawl): add teammate-crawl config keys (flag, depth, recent threshold)"
```

---

## Task 2: Document the new env vars in `.env.example`

**Files:**
- Modify: `backend/.env.example`

- [ ] **Step 1: Locate the existing `BLIZZARD_SYNC_*_ENABLED` block**

Run:
```bash
grep -n "BLIZZARD_SYNC\|BLIZZARD_STALE\|BLIZZARD_CRAWL" .env.example
```

Expected: hits on the four `BLIZZARD_SYNC_*_ENABLED` lines (mythic_plus, pvp, professions, raids); no hits for `BLIZZARD_CRAWL`.

- [ ] **Step 2: Add the new env keys after the four existing sync flags**

Find the block (the line numbers may have shifted; search by content):

```
BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED=true
BLIZZARD_SYNC_PVP_ENABLED=true
BLIZZARD_SYNC_PROFESSIONS_ENABLED=true
BLIZZARD_SYNC_RAIDS_ENABLED=true
```

Append three lines so the block becomes:

```
BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED=true
BLIZZARD_SYNC_PVP_ENABLED=true
BLIZZARD_SYNC_PROFESSIONS_ENABLED=true
BLIZZARD_SYNC_RAIDS_ENABLED=true
# Recursive crawl from a Full-sync seed → its M+ teammates (Standard depth, blizzard-background queue).
BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED=false
BLIZZARD_CRAWL_MAX_DEPTH=1
BLIZZARD_CRAWL_RECENT_THRESHOLD=21600
```

- [ ] **Step 3: Verify**

Run:
```bash
grep -c "BLIZZARD_CRAWL\|BLIZZARD_SYNC_TEAMMATE_CRAWL" .env.example
```

Expected: `3`.

- [ ] **Step 4: Commit**

```bash
git add .env.example
git commit -m "docs(crawl): document teammate-crawl env vars in .env.example"
```

---

## Task 3: Add `crawlDepth` constructor arg + queue routing to `SyncCharacterData`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Locate the constructor**

Run:
```bash
grep -n "public function __construct\|onQueue" app/Blizzard/Jobs/SyncCharacterData.php
```

Expected (line numbers approximate):
```
64:    public function __construct(
71:        $this->onQueue('blizzard-user-sync');
```

- [ ] **Step 2: Replace the constructor**

Find this exact block:

```php
    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        public readonly SyncDepth $depth = SyncDepth::Standard,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('blizzard-user-sync');
    }
```

Replace with:

```php
    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        public readonly SyncDepth $depth = SyncDepth::Standard,
        public readonly ?int $userId = null,
        public readonly int $crawlDepth = 0,
    ) {
        // Crawled teammate jobs (crawlDepth > 0) land on the lowest-priority
        // queue so they cannot starve user-initiated lookups. The seed
        // (crawlDepth=0) keeps the existing user-sync queue assignment.
        $this->onQueue($crawlDepth > 0 ? 'blizzard-background' : 'blizzard-user-sync');
    }
```

Note: `uniqueId()` is intentionally NOT changed (see Design decisions §3 — including `crawlDepth` in the unique key would cause double-spend on Blizzard quota when a seed and a crawl both target the same character within 60s).

- [ ] **Step 3: Sanity-check the file parses**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: `No syntax errors detected ...`.

- [ ] **Step 4: Verify all existing dispatch sites still type-check**

Run:
```bash
grep -rn "SyncCharacterData::dispatch\|new SyncCharacterData" app tests
```

Expected: 8 hits across `ProactiveSyncCharacters.php`, `SyncUserCharacters.php`, `SyncGuildRoster.php`, `BackfillSlices.php`, `CharacterController.php`, `CharacterService.php` (×2), `SyncCharacterDataNotFoundTest.php`. None of them pass a 6th argument; the new `crawlDepth` parameter has a default of `0` so all calls remain valid. **Do not modify any of these call sites in this task.**

- [ ] **Step 5: Run the existing job test to confirm no regression**

Run:
```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php
```

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "feat(crawl): add crawlDepth ctor arg to SyncCharacterData (defaults to 0)"
```

---

## Task 4: Implement `dispatchTeammateCrawl()` private method

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Read the method skeleton you'll insert**

The method goes immediately before the existing `failed()` method (around line 684 in the unmodified file). It performs:

1. Early return if `$this->crawlDepth >= config('blizzard.crawl.max_depth')`.
2. Early return if `! config('blizzard.sync.teammate_crawl_enabled')`.
3. Resolve the seed's persisted `Character` row via `byIdentity`. Bail if missing (the seed sync hit a 404 path and never persisted).
4. Look up the **current** Mythic+ season id via `$gameDataClient->getCurrentMythicPlusSeason()` — already injected into `handle()`. (Pass it through.)
5. Read the seed's dungeon-run-member rows for `dungeon_runs.season = currentSeason` via the existing `$character->dungeonRuns()` relation, eager-loading `members`.
6. Flatten members → `(name, realm, region)` triples; dedupe; drop the seed itself.
7. For each, call `Character::byIdentity(...)` to find the existing row. Skip if `synced_at` is within `BLIZZARD_CRAWL_RECENT_THRESHOLD`.
8. Dispatch `SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard, null, $this->crawlDepth + 1)`.
9. Wrap the whole body in `try/catch Throwable` → `Log::warning` (defense-in-depth).

- [ ] **Step 2: Verify the seed Character row has a `dungeonRuns` relation**

Run:
```bash
grep -n "dungeonRuns\|function dungeon" app/Models/Character.php
```

Expected: a `dungeonRuns()` `belongsToMany` (or similar) relation exists. If absent, **stop and report** — this plan assumes the relation already exists for the FE M+ rendering path. (Per CLAUDE.md and the existing `MythicPlusRuns.vue` consumer, it must.)

If the relation lives on `DungeonRun` only (i.e., `DungeonRun::members()`), use the inverse query path: `DungeonRun::where('season', $currentSeason)->whereHas('members', fn ($q) => $q->where('character_id', $character->id))->with('members')->get()`. Same effect.

- [ ] **Step 3: Pass `$gameDataClient` and the seed `$character` model into `dispatchTeammateCrawl()`**

Modify `handle()` to call the new method as the very last statement. Find this in `handle()` (around lines 230-241):

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncStats($client, $statsMapper, $character);
            $this->syncTitles($client, $titleMapper, $character);
            $this->syncReputations($client, $reputationMapper, $character);
            $this->syncCollections($client, $mountMapper, $petMapper, $toyMapper, $character);
            $this->syncAchievements($client, $achievementMapper, $character);
        }
    }
```

Replace with (one extra line before the closing brace; the conditional is intentional — no point crawling teammates when the seed didn't sync M+ data):

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncStats($client, $statsMapper, $character);
            $this->syncTitles($client, $titleMapper, $character);
            $this->syncReputations($client, $reputationMapper, $character);
            $this->syncCollections($client, $mountMapper, $petMapper, $toyMapper, $character);
            $this->syncAchievements($client, $achievementMapper, $character);

            $this->dispatchTeammateCrawl($gameDataClient, $character);
        }
    }
```

- [ ] **Step 4: Insert the new private method**

Insert the following method definition immediately before `public function failed(Throwable $exception): void` (around line 684):

```php
    /**
     * Recursive fan-out: dispatch a Standard-depth sync for each Mythic+
     * teammate of the seed character we just synced. Gated on
     * BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED and capped at BLIZZARD_CRAWL_MAX_DEPTH.
     *
     * Runs at the end of handle() — after every slice has committed — so
     * a dispatch failure here never rolls back the seed's persisted data.
     */
    private function dispatchTeammateCrawl(
        BlizzardGameDataClient $gameDataClient,
        Character $character,
    ): void {
        if (! config('blizzard.sync.teammate_crawl_enabled')) {
            return;
        }

        $maxDepth = (int) config('blizzard.crawl.max_depth', 1);

        // Hard ceiling: never honor max_depth > 2 even if the env says so.
        // Depth 2 = ~1k characters per seed at full saturation; 3+ would
        // exhaust the hourly Blizzard quota in a single seed cycle.
        $maxDepth = min($maxDepth, 2);

        if ($this->crawlDepth >= $maxDepth) {
            return;
        }

        try {
            $season = $gameDataClient->getCurrentMythicPlusSeason();

            $rows = DB::table('dungeon_run_members')
                ->join('dungeon_runs', 'dungeon_runs.id', '=', 'dungeon_run_members.dungeon_run_id')
                ->where('dungeon_runs.season', $season)
                ->whereIn('dungeon_runs.id', function ($q) use ($character, $season) {
                    $q->select('dungeon_run_id')
                        ->from('dungeon_run_members')
                        ->where('character_id', $character->id)
                        ->whereIn('dungeon_run_id', function ($q2) use ($season) {
                            $q2->select('id')->from('dungeon_runs')->where('season', $season);
                        });
                })
                ->select(
                    'dungeon_run_members.character_name',
                    'dungeon_run_members.character_realm',
                    'dungeon_run_members.character_region',
                )
                ->get();

            $threshold = (int) config('blizzard.crawl.recent_threshold', 21600);
            $cutoff = now()->subSeconds($threshold);
            $seen = [];
            $dispatched = 0;

            foreach ($rows as $row) {
                $name = strtolower((string) $row->character_name);
                $realm = (string) $row->character_realm;
                $region = (string) $row->character_region;

                $key = "{$region}:{$realm}:{$name}";

                // Dedupe within the batch.
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                // Skip the seed itself.
                if ($name === strtolower($this->name)
                    && $realm === $this->realm
                    && $region === $this->region) {
                    continue;
                }

                // Skip if Blizzard didn't give us all 3 identity fields
                // (defensive — pivot is NOT NULL on all three, but be safe).
                if ($name === '' || $realm === '' || $region === '') {
                    continue;
                }

                // Skip if a Character row exists and is fresher than the threshold.
                $existing = Character::byIdentity($name, $realm, $region)->first();
                if ($existing && $existing->synced_at && $existing->synced_at->greaterThan($cutoff)) {
                    continue;
                }

                SyncCharacterData::dispatch(
                    $region,
                    $realm,
                    $name,
                    SyncDepth::Standard,
                    null,
                    $this->crawlDepth + 1,
                );
                $dispatched++;
            }

            Log::info('Teammate crawl dispatched', [
                'seed' => "{$this->name}-{$this->realm}-{$this->region}",
                'seed_crawl_depth' => $this->crawlDepth,
                'teammates_dispatched' => $dispatched,
                'teammates_skipped_fresh' => count($seen) - $dispatched,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch teammate crawl', [
                'seed' => "{$this->name}-{$this->realm}-{$this->region}",
                'crawl_depth' => $this->crawlDepth,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

Note: the query uses `DB::table` rather than the Eloquent relation because we only need three string columns from the pivot — no Eloquent rehydration cost, single query, no N+1 risk regardless of how many runs the seed has.

- [ ] **Step 5: Verify the file parses**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: clean.

- [ ] **Step 6: Smoke-test the dispatch path with the flag OFF (default)**

Run:
```bash
docker compose exec -T app php artisan tinker --execute="
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use Illuminate\Support\Facades\Bus;
Bus::fake();
SyncCharacterData::dispatch('eu', 'the-maelstrom', 'melaniya', SyncDepth::Full);
Bus::assertDispatched(SyncCharacterData::class, 1);
echo 'OK: only the seed dispatched (flag off, no fan-out).' . PHP_EOL;
"
```

Expected: `OK: only the seed dispatched (flag off, no fan-out).`

(This only exercises the dispatch graph, not the network. Full integration coverage lives in Task 6.)

- [ ] **Step 7: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "feat(crawl): dispatch Standard-depth sync for each M+ teammate from Full seed"
```

---

## Task 5: Pass `crawlDepth=0` explicitly at the few seed-dispatch sites that should remain "shallow" non-crawl entry points

**Files:**
- Read-only inspection (no edits expected unless an entry point is identified that should NOT crawl)

The new param defaults to `0` so all existing call sites continue working. However, three of them dispatch from contexts where we explicitly **don't want** further fan-out from this entry point even at depth 0 (i.e., they should not begin a crawl tree):

- `SyncGuildRoster.php` (line ~57): dispatches at `SyncDepth::Shallow`. `Shallow` doesn't enter the `if ($this->depth === SyncDepth::Full)` branch in `handle()`, so the crawl never runs. **No change.**
- `CharacterController.php` (line ~33): dispatches at `SyncDepth::Standard`. Same reason. **No change.**
- `BackfillSlices.php` (line ~39): dispatches at `SyncDepth::Full`. We **do** want this to crawl (it's the on-demand backfill — by the user's design intent the crawl should expand from any Full sync). **No change.**

`ProactiveSyncCharacters.php`, `SyncUserCharacters.php`, `CharacterService.php`: all dispatch at `Full` (tier-1 path) or `Standard` (tier-2 / no-stale-slice path). The `Full` paths should crawl by design. **No change.**

- [ ] **Step 1: Audit the dispatch sites**

Run:
```bash
grep -B1 -A6 "SyncCharacterData::dispatch\|new SyncCharacterData" app
```

Expected: confirm each site matches the expected depth above. If any `Full`-depth dispatch exists that should *not* crawl (e.g., a future test fixture loader), the operator can pass `crawlDepth: $maxDepth` to short-circuit.

- [ ] **Step 2: No code changes; document in the commit message**

```bash
git commit --allow-empty -m "chore(crawl): audit existing dispatch sites — all default crawlDepth=0 is correct"
```

(Empty commit serves as a paper trail that the audit happened. Skip if your repo's hooks reject empty commits — recap in the PR description instead.)

---

## Task 6: Tests

**Files:**
- Create: `backend/tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php`

The test file uses `Bus::fake()` to assert dispatch graphs without actually running Blizzard calls. Tests use the existing `EndpointIntegrationTestCase` baseline (SQLite in-memory, sync queue, array cache — see `phpunit.xml`).

- [ ] **Step 1: Write the failing test file**

Create `backend/tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SyncCharacterDataTeammateCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('blizzard.sync.teammate_crawl_enabled', true);
        Config::set('blizzard.crawl.max_depth', 1);
        Config::set('blizzard.crawl.recent_threshold', 21600);
    }

    private function makeSeedWithRun(): Character
    {
        $seed = Character::factory()->create([
            'name' => 'seedy',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'game_version' => 'retail',
            'mythics_synced_at' => now(),
            'synced_at' => now(),
        ]);

        $run = DungeonRun::factory()->create([
            'season' => 'season-mn-1',
            'dungeon_id' => 1234,
            'completed_timestamp' => 1_700_000_000_000,
        ]);

        // Five distinct rows on the pivot — the post-pivot-fix shape.
        $run->members()->createMany([
            [
                'character_name' => 'seedy', 'character_realm' => 'the-maelstrom', 'character_region' => 'eu',
                'character_id' => $seed->id, 'spec_name' => 'Demonology', 'equipped_item_level' => 600,
            ],
            [
                'character_name' => 'mateone', 'character_realm' => 'twisting-nether', 'character_region' => 'eu',
                'character_id' => null, 'spec_name' => 'Frost', 'equipped_item_level' => 605,
            ],
            [
                'character_name' => 'matetwo', 'character_realm' => 'silvermoon', 'character_region' => 'eu',
                'character_id' => null, 'spec_name' => 'Restoration', 'equipped_item_level' => 602,
            ],
            [
                'character_name' => 'matethree', 'character_realm' => 'kazzak', 'character_region' => 'eu',
                'character_id' => null, 'spec_name' => 'Havoc', 'equipped_item_level' => 599,
            ],
            [
                'character_name' => 'matefour', 'character_realm' => 'draenor', 'character_region' => 'eu',
                'character_id' => null, 'spec_name' => 'Holy', 'equipped_item_level' => 604,
            ],
        ]);

        return $seed;
    }

    public function test_crawl_disabled_dispatches_only_seed(): void
    {
        Config::set('blizzard.sync.teammate_crawl_enabled', false);
        Bus::fake();

        SyncCharacterData::dispatch('eu', 'the-maelstrom', 'seedy', SyncDepth::Full);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 1);
    }

    public function test_seed_at_max_depth_does_not_fan_out(): void
    {
        Config::set('blizzard.crawl.max_depth', 1);
        Bus::fake();

        // crawlDepth=1 with max_depth=1 → no fan-out.
        SyncCharacterData::dispatch('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 1);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 1);
    }

    public function test_recently_synced_teammate_is_skipped(): void
    {
        $seed = $this->makeSeedWithRun();

        // Pre-create a teammate that was synced 10s ago — well within the 21600s threshold.
        Character::factory()->create([
            'name' => 'mateone',
            'realm' => 'twisting-nether',
            'region' => 'eu',
            'game_version' => 'retail',
            'synced_at' => now()->subSeconds(10),
        ]);

        // We can't run the real handle() (would hit Blizzard); instead invoke the
        // private crawl method directly via reflection after seeding.
        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full);

        Bus::fake();

        $reflection = new \ReflectionMethod($job, 'dispatchTeammateCrawl');
        $reflection->setAccessible(true);

        $gameDataClient = $this->createMock(\App\Blizzard\Client\BlizzardGameDataClient::class);
        $gameDataClient->method('getCurrentMythicPlusSeason')->willReturn('season-mn-1');

        $reflection->invoke($job, $gameDataClient, $seed);

        // Expect 3 dispatches (mateone is fresh; matetwo/three/four are new).
        Bus::assertDispatchedTimes(SyncCharacterData::class, 3);
    }

    public function test_stale_teammate_is_dispatched_at_standard_and_depth_plus_one(): void
    {
        $seed = $this->makeSeedWithRun();

        // Pre-create a stale teammate (synced > threshold ago).
        Character::factory()->create([
            'name' => 'mateone',
            'realm' => 'twisting-nether',
            'region' => 'eu',
            'game_version' => 'retail',
            'synced_at' => now()->subSeconds(99999),
        ]);

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 0);

        Bus::fake();
        $reflection = new \ReflectionMethod($job, 'dispatchTeammateCrawl');
        $reflection->setAccessible(true);

        $gameDataClient = $this->createMock(\App\Blizzard\Client\BlizzardGameDataClient::class);
        $gameDataClient->method('getCurrentMythicPlusSeason')->willReturn('season-mn-1');

        $reflection->invoke($job, $gameDataClient, $seed);

        // 4 dispatches (all 4 teammates; seed itself is filtered).
        Bus::assertDispatchedTimes(SyncCharacterData::class, 4);

        // Confirm the dispatched job has crawlDepth=1, depth=Standard, and the right queue.
        Bus::assertDispatched(SyncCharacterData::class, function ($job) {
            return $job->crawlDepth === 1
                && $job->depth === SyncDepth::Standard
                && $job->queue === 'blizzard-background';
        });
    }

    public function test_seed_is_filtered_out_of_crawl_targets(): void
    {
        $seed = $this->makeSeedWithRun();

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full);

        Bus::fake();
        $reflection = new \ReflectionMethod($job, 'dispatchTeammateCrawl');
        $reflection->setAccessible(true);

        $gameDataClient = $this->createMock(\App\Blizzard\Client\BlizzardGameDataClient::class);
        $gameDataClient->method('getCurrentMythicPlusSeason')->willReturn('season-mn-1');

        $reflection->invoke($job, $gameDataClient, $seed);

        // 4, not 5 — `seedy` is in the pivot but filtered.
        Bus::assertDispatchedTimes(SyncCharacterData::class, 4);
        Bus::assertNotDispatched(SyncCharacterData::class, fn ($job) =>
            $job->name === 'seedy' && $job->crawlDepth === 1
        );
    }

    public function test_duplicate_teammates_across_runs_dispatch_once(): void
    {
        $seed = $this->makeSeedWithRun();

        // Add a second run with overlap.
        $run2 = DungeonRun::factory()->create([
            'season' => 'season-mn-1',
            'dungeon_id' => 5678,
            'completed_timestamp' => 1_700_000_001_000,
        ]);
        $run2->members()->createMany([
            ['character_name' => 'seedy', 'character_realm' => 'the-maelstrom', 'character_region' => 'eu', 'character_id' => $seed->id],
            ['character_name' => 'mateone', 'character_realm' => 'twisting-nether', 'character_region' => 'eu', 'character_id' => null],
            ['character_name' => 'newmate', 'character_realm' => 'argent-dawn', 'character_region' => 'eu', 'character_id' => null],
        ]);

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full);
        Bus::fake();
        $r = new \ReflectionMethod($job, 'dispatchTeammateCrawl');
        $r->setAccessible(true);
        $gd = $this->createMock(\App\Blizzard\Client\BlizzardGameDataClient::class);
        $gd->method('getCurrentMythicPlusSeason')->willReturn('season-mn-1');

        $r->invoke($job, $gd, $seed);

        // 4 unique teammates from run 1 + 1 new from run 2 = 5; mateone overlap dedupes.
        Bus::assertDispatchedTimes(SyncCharacterData::class, 5);
    }

    public function test_max_depth_clamped_to_2(): void
    {
        Config::set('blizzard.crawl.max_depth', 999);
        $seed = $this->makeSeedWithRun();

        // crawlDepth=2 with clamped max=2 → no fan-out.
        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 2);
        Bus::fake();
        $r = new \ReflectionMethod($job, 'dispatchTeammateCrawl');
        $r->setAccessible(true);
        $gd = $this->createMock(\App\Blizzard\Client\BlizzardGameDataClient::class);
        $gd->method('getCurrentMythicPlusSeason')->willReturn('season-mn-1');

        $r->invoke($job, $gd, $seed);

        Bus::assertNotDispatched(SyncCharacterData::class);
    }
}
```

- [ ] **Step 2: Run the test (initially expecting any pre-pivot-fix issues to surface)**

Run:
```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php
```

Expected: all 7 tests green. If a test fails:
- "ReflectionMethod dispatchTeammateCrawl does not exist" → Task 4 wasn't applied; revisit.
- "DungeonRun::factory does not exist" → no factory in the repo; create a minimal `DungeonRunFactory.php` (and member shape) per the project's existing factory conventions, OR replace the factory calls with `DungeonRun::create([...])` direct seeding. Match what the project's other sync-job tests do.
- "Character::factory does not exist" → same fallback.

If any factory is missing, **stop and ask** before extending — the project conventions dictate factory shape.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php
git commit -m "test(crawl): cover crawl gating, dedup, depth cap, and fresh-skip logic"
```

---

## Task 7: Update `backend/CLAUDE.md` to document the new behavior

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Add a new bullet under the "Architecture" → Blizzard Module bullet list**

Find the bullet starting with `**Per-slice Full sync.**` (around the bullet list in §Blizzard Module). After the **ProactiveSyncCharacters tier 1 dispatches Full.** bullet, add:

```markdown
- **Recursive teammate crawl.** When `SyncCharacterData` runs at `SyncDepth::Full` (i.e., a seed character), the `dispatchTeammateCrawl()` method runs at the very end of `handle()` and dispatches one `SyncDepth::Standard` `SyncCharacterData` job per Mythic+ teammate found in the seed's persisted `dungeon_run_members` rows for the current season — onto `blizzard-background`, with `crawlDepth = $this->crawlDepth + 1`. Gated on `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` (default `false`); depth-capped via `BLIZZARD_CRAWL_MAX_DEPTH` (default `1`, hard-clamped to `2` in code); skips teammates whose `Character.synced_at` is fresher than `BLIZZARD_CRAWL_RECENT_THRESHOLD` seconds (default `21600` = 6h). Crawled jobs ride the same `BlizzardRateLimiter` + `BlizzardHealthCheck` middleware as user-initiated syncs and dedupe via the existing `ShouldBeUnique` (60s, region:realm:name:depth). The crawl is `Standard`-depth specifically — `Full` would re-crawl from each teammate and explode the fan-out factor, while `Standard` gives the FE enough data (profile + media + equipment + specs) to render that teammate as a member of the seed's runs; the teammate's own `Full` data is fetched lazily on next user lookup. Worst-case fan-out at depth=1 from one seed: ~32 teammate jobs × 4 Blizzard requests each = 128 requests, well under the 80 req/s budget over a few seconds.
```

- [ ] **Step 2: Verify**

Run:
```bash
grep -n "teammate crawl\|teammate_crawl\|BLIZZARD_CRAWL" CLAUDE.md
```

Expected: at least 3 hits (the bullet + the env var mentions inside it).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(crawl): document recursive teammate-crawl behavior in CLAUDE.md"
```

---

## Task 8: Pint format check

**Files:** none (verification only)

- [ ] **Step 1: Run Pint in test mode**

```bash
docker compose exec -T app ./vendor/bin/pint --test
```

Expected: clean. If it complains, run `./vendor/bin/pint`, re-stage, and:

```bash
git add -A
git commit -m "style(crawl): pint after crawl edits"
```

---

## Task 9: Run full BE test suite

**Files:** none

- [ ] **Step 1: Run the full suite**

```bash
docker compose exec -T app composer test
```

Expected: all green. Notable signals to check:
- The new `SyncCharacterDataTeammateCrawlTest` class runs 7 tests and all pass.
- No regressions in existing `SyncCharacterDataNotFoundTest` or any retail/character endpoint tests.
- No "Method not found" / "Argument count" errors from existing dispatch sites — the new constructor arg is a default, so all 8 call sites continue to work.

- [ ] **Step 2: If anything fails, diagnose and fix**

Common modes:
- A factory the new test depends on (DungeonRun, Character) doesn't exist or has different defaults. Inspect existing test files for the right shape.
- Eloquent's `synced_at` relation/cast is inconsistent. Check `Character::$casts` for `synced_at`.

Make the smallest change that gets the suite green; commit separately if a real bug surfaces.

---

## Task 10: Manual verification on dev (Horizon + DB)

**Files:** none (operator-only)

This is run against the local docker stack with the flag flipped on temporarily.

- [ ] **Step 1: Snapshot pre-state**

```bash
docker compose exec -T postgres psql -U guild_service -d guild_service -c "
SELECT COUNT(*) AS retail_chars FROM characters WHERE game_version='retail';
SELECT COUNT(*) AS run_member_rows FROM dungeon_run_members;
SELECT COUNT(*) AS distinct_teammate_identities
FROM (SELECT DISTINCT character_name, character_realm, character_region FROM dungeon_run_members) t;
"
```

Save the three counts.

- [ ] **Step 2: Flip the flag in `.env`, reload config, restart Horizon**

In the local `.env` (NOT committed):
```
BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED=true
```

Then:
```bash
docker compose exec -T app php artisan config:clear
docker compose restart horizon
```

- [ ] **Step 3: Trigger a seed sync from a known character**

Pick a high-rated retail character from the test fixtures. Either:

```bash
docker compose exec -T app php artisan tinker --execute="
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
SyncCharacterData::dispatch('eu', 'the-maelstrom', 'melaniya', SyncDepth::Full);
echo 'Dispatched seed.' . PHP_EOL;
"
```

Or, if a controller route exists, hit `GET /api/v1/characters/eu/the-maelstrom/melaniya?refresh=1` (Plan 3 wiring).

- [ ] **Step 4: Watch Horizon for the fan-out**

Open `http://localhost:8091/horizon` (or whichever the project uses). After ~30s confirm:
- 1 job on `blizzard-user-sync` (the seed) — completed.
- N jobs on `blizzard-background` (the teammates) — pending → completed over the next minute or two.

- [ ] **Step 5: Verify new `Character` rows**

Re-run the count query from Step 1. Expected:
- `retail_chars` increased by approximately the number of unique teammates from the seed's runs (capped by the recent-skip rule and `ShouldBeUnique`).
- New rows have `synced_at IS NOT NULL` and `mythics_synced_at IS NULL` (Standard-depth — no M+ slice).

Sample:
```bash
docker compose exec -T postgres psql -U guild_service -d guild_service -c "
SELECT name, realm, region, synced_at, mythics_synced_at, equipped_item_level
FROM characters
WHERE game_version='retail'
ORDER BY synced_at DESC NULLS LAST
LIMIT 10;
"
```

- [ ] **Step 6: Verify crawl log lines**

```bash
docker compose exec -T app tail -n 200 storage/logs/laravel.log | grep -E "Teammate crawl dispatched|Failed to dispatch teammate crawl"
```

Expected: at least one `Teammate crawl dispatched` line with `seed`, `seed_crawl_depth=0`, `teammates_dispatched=N`, `teammates_skipped_fresh=M`. If the depth cap worked, the dispatched teammate jobs each emit their own `Teammate crawl dispatched` log too — but with `teammates_dispatched=0` because `crawlDepth=1 >= max_depth=1`.

- [ ] **Step 7: Confirm rate-limit budget held**

```bash
docker compose exec -T app php artisan tinker --execute="
echo 'Tokens left in last second: ' . (\Illuminate\Support\Facades\Redis::get('blizzard:rate-limit:remaining') ?? 'n/a') . PHP_EOL;
"
```

(Approximate — the actual key shape lives in `BlizzardRateLimiter`; inspect that file if needed. The point is to confirm the limiter is engaged for the crawled jobs, not bypassed.)

- [ ] **Step 8: Flip the flag back off in `.env`**

```
BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED=false
```

```bash
docker compose exec -T app php artisan config:clear
docker compose restart horizon
```

- [ ] **Step 9: No code commits in this task**

The flag stays `false` in `.env.example` and config defaults. Operator turns it on per environment when ready.

---

## Open questions

- **Should the crawl prefer "stale-on-profile" over "stale-on-anything"?** Right now it skips on `synced_at` (top-level profile sync). If a teammate has fresh profile but stale M+, we don't re-dispatch. That's intentional for v1 — Standard depth doesn't touch M+ anyway, so re-dispatching to update Standard data is the right semantic. Revisit only if observation shows teammates frequently appearing with stale equipment.
- **Multi-seed coordination.** If two seeds crawl simultaneously and pick the same teammate, `ShouldBeUnique` (60s) dedupes the second dispatch — but only within a 60s window. After 60s the second dispatch could fire and re-spend Blizzard quota. The recent-skip threshold (6h) catches this for the *third* attempt and beyond. Acceptable for v1. If observation shows duplicate fetches in practice, consider lengthening `uniqueFor` for crawled jobs only.
- **Depth=2 readiness.** This plan caps at 1 by default. If operator data shows fan-out is well-behaved, raising to 2 in env (`BLIZZARD_CRAWL_MAX_DEPTH=2`) is one config flip — no code change needed, the clamp accommodates it.
- **Cross-region teammates.** Today, the M+ team-member pivot stores `character_region` per row, defaulting to the seed's region (because Blizzard's M+ best-runs response gives realm slugs but not region — `syncMythicPlus()` line 287 hardcodes `$this->region`). The crawl will dispatch all teammates as if in the seed's region. Cross-region teammates do not appear in the M+ best-runs response, so this is correct for the current API shape. If a future Blizzard API change exposes cross-region teammates, revisit.

---

## Verification checklist (for PR description)

- [ ] `composer test` green; new `SyncCharacterDataTeammateCrawlTest` covers 7 cases.
- [ ] `php -l` clean on `config/blizzard.php` and `app/Blizzard/Jobs/SyncCharacterData.php`.
- [ ] Pint clean.
- [ ] Manual dev verification (Task 10) recorded with seed character name, pre/post counts, Horizon screenshot or job-id list.
- [ ] Crawl log line confirmed in `storage/logs/laravel.log` after dev sync.
- [ ] Flag flipped back to `false` in dev `.env` after verification.
- [ ] `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` is **NOT** flipped to `true` in any committed config — only the env-default and the `.env.example` documentation lines change.
- [ ] PR description references prerequisite plan `2026-05-01-mythic-plus-team-pivot-fix.md` and confirms it merged first.

