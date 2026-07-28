# RaiderIO Seeder

Fragment extracted from `CLAUDE.md`. Primary reference: `../CLAUDE.md`.

---

Lean discovery layer for bootstrapping from raider.io top-lists (`app/Services/RaiderIO/`). **Not a full module** — DTOs do not leak past `RaiderIOSeeder`; nothing raider.io-shaped is persisted. Reuses existing Blizzard sync jobs end-to-end (dispatch and forget).

## Architecture

`RaiderIOClient` (Laravel `Http::` + Redis token-bucket throttle, default 175/min vs raider.io's 200/min ceiling), `RaiderIOSeeder` orchestrator (`seedGuilds`, `seedRuns`), `php artisan raiderio:seed`. Generators yield rows lazily — at most ~20 rows in memory at a time.

The runs phase walks **more than one ladder per region**: first the global (all-dungeons) top list, then one ladder per season dungeon (`/mythic-plus/runs?...&dungeon={slug}`). The global list is meta-dungeon-biased; per-dungeon lists surface distinct rosters. Dungeon slugs come from `RaiderIOClient::seasonDungeonSlugs()` → `/mythic-plus/static-data?expansion_id={RAIDERIO_EXPANSION_ID}` (config `raiderio.expansion_id`, default **11 = Midnight** — bump it alongside `season:rollover` at an expansion boundary), matched against the current `Seasons::raiderioSeasonSlug()`. A static-data failure is logged + counted in `errors` and the phase falls back to the global ladder only.

**Scheduled daily 01:00 UTC** (`bootstrap/app.php`): `raiderio:seed --phase=all`. Discovery breadth and freshness come from prod env: `RAIDERIO_SEED_GUILDS_PER_REGION=150`, `RAIDERIO_SEED_RUNS_PAGES_PER_REGION=100`, `RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON=20`, `RAIDERIO_SEED_MAX_GUILD_DISPATCHES_PER_REGION=25`, `RAIDERIO_SEED_MAX_CHAR_DISPATCHES_PER_REGION=8000`, `RAIDERIO_SEED_CHAR_TTL=604800` (weekly re-sync), `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED=true`.

## Phases

- **Phase 1 — Guilds.** `--phase=guilds` pulls top mythic raiding guilds via `/raiding/raid-rankings?raid={RAIDERIO_CURRENT_RAID_TIER}&difficulty=mythic`, dispatches `SyncGuildData`. `SyncGuildRoster` then dispatches `SyncCharacterData::Full` per roster member (TTL-gated on `Character.updated_at`).
- **Phase 2 — Runs.** `--phase=runs` pulls top M+ runs from `/mythic-plus/runs?season={s}&region={r}&page={N}` (20 runs/page; `--limit` is *pages*, and applies to the **global ladder only**). Each run yields 5 character refs; per-member `SyncCharacterData::Full`. Run-level dedupe via `seeded_runs` on `keystone_run_id` (immutable); since 2026-07-19 the ledger no longer skips members — every member of every listed run goes through the per-character TTL gate (`RAIDERIO_SEED_CHAR_TTL`), so stale/missed members of known runs are picked up on later passes.
  - **Per-dungeon ladders.** After the global ladder, the region is walked once per season dungeon, `RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON` pages each (**0 = off**, global ladder only — the default; prod runs 20). Config-only, no CLI flag. Cost per region ≈ `pages_per_region + dungeons × pages_per_dungeon` requests.
  - **`page > 100` on `/mythic-plus/runs` requires `RAIDERIO_ACCESS_KEY`** — raider.io rejects deeper paging for unauthenticated apps, so a key is a hard prerequisite for `RAIDERIO_SEED_RUNS_PAGES_PER_REGION > 100`.
  - **In-invocation member dedupe.** The same top players recur across ladders, so `seedRuns()` keeps a per-region `realm:name` set and dispatches a member identity **at most once per invocation**; recurrences are counted as `skipped_dedupe` (the same column the run ledger uses) and never burn a cap slot. The set records *dispatched* identities only — members skipped by the TTL gate are re-probed on each recurrence. Cross-invocation dedupe is unchanged: job-level `ShouldBeUnique` plus the TTL gate.
  - A throttle (429) or API error now advances to the **next ladder**, not the next region — one bad dungeon slug no longer costs the rest of the region.
- **Phase 3 — Cancelled.** raider.io has no public per-character/per-spec ranking endpoint. To broaden coverage, bump `RAIDERIO_SEED_GUILDS_PER_REGION` / `RAIDERIO_SEED_RUNS_PAGES_PER_REGION` / `RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON`.

## Dispatch caps

Per-region, per-invocation ceilings on how many jobs a single seed run may push into the Blizzard queue — breadth knobs can now vastly outrun what Horizon drains in a night, so the caps, not the page counts, are the real throttle.

- `RAIDERIO_SEED_MAX_GUILD_DISPATCHES_PER_REGION` (guilds phase) and `RAIDERIO_SEED_MAX_CHAR_DISPATCHES_PER_REGION` (runs phase). **0 = uncapped** (default); prod runs 25 / 8000.
- Counters reset per region and per invocation. Overflow is counted in the `skipped_cap` report column (`raiderio:seed` table + the `raiderio.seed.complete` log line).
- Nothing is lost: capped entries stay stale, so the TTL gate re-offers them on later nights — coverage ramps instead of flooding.
- **Runs abandon the region at the cap** — once the char cap trips, the remaining pages *and* remaining dungeon ladders for that region are skipped, so no raider.io requests are spent on members that wouldn't be dispatched. **Guilds keep consuming** the (cheap, already-paged) generator so `considered`/`skipped_cap` stay meaningful.
- `--dry-run` counts its would-be dispatches against the caps, so a dry run predicts real behaviour instead of reporting an uncapped total.

## Roster fan-out

Opt-in per dispatch. `SyncGuildRoster` dispatches per-member `SyncCharacterData::Full` only when `forceFanout: true` was passed (seeder sets this) — the old `raiderio.dispatch_roster_character_syncs` global config flag is gone; `forceFanout` is the only switch. Guild syncs are on-demand only (the weekly `ProactiveSyncGuilds` sweep was deleted 2026-07-13); user-initiated `SyncGuildData` does NOT cascade per-member. Members above `BLIZZARD_MIN_LEVEL_FOR_LOOKUP` only.

## Teammate crawl override

`RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` (default false) overrides the global flag for seed-originated dispatches. Plumbed via `bool $forceTeammateCrawl` ctor param on `SyncCharacterData`. Crawled descendants get `false` (override doesn't recurse). Used by phases 2/3, not phase 1 (guild fan-out runs from `SyncGuildRoster`, not the seeder).

## Rate limits

raider.io 200/min public; `RAIDERIO_ACCESS_KEY` (registered apps) unlocks higher rates and is appended when set. 429 throws a typed `RaiderIOThrottledException` (no in-process retry/sleep) — `retryAfter` comes from the `Retry-After` header, defaults to 60s absent, capped at 90s. `RaiderIORateLimiter` job middleware catches it and non-blockingly `release()`s the job for that many seconds; the seeder's own console loops (`seedGuilds`/`seedRuns`) catch it directly and `sleep(min(retryAfter, 90)); continue;`. 5xx still retried up to 3 with backoff [1, 4, 10]s. Phpunit sets `RAIDERIO_BACKOFF_SLEEP_ENABLED=0`. Blizzard side: existing 80/s, 30k/hr ceiling — ~1500 Full character syncs/hour; queue drains over hours.

## Future

If usage broadens beyond discovery, promote to a full `app/RaiderIO/` module mirroring `app/Blizzard/`.

## Common invocations

```bash
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu --dry-run
php artisan raiderio:seed --phase=guilds                          # config defaults: 10 x eu,us
php artisan raiderio:seed --phase=runs --limit=5 --regions=eu,us
php artisan raiderio:seed --phase=guilds --regions=eu --force     # bypass TTL (NOT seeded_runs ledger)
```

Flag semantics are unchanged: `--limit` is *guilds per region* for `--phase=guilds` and *global-ladder pages per region* for `--phase=runs` (it never touches the per-dungeon ladders). Per-dungeon pages and the dispatch caps are env/config-only. An inline override (`RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON=2 php artisan raiderio:seed --phase=runs --regions=eu --dry-run`) works locally but **not in the deployed container** — the entrypoint runs `config:cache`, so a one-off there needs a `.env` edit or `php artisan config:clear` first.
