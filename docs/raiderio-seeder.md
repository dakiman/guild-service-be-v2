# RaiderIO Seeder

Fragment extracted from `CLAUDE.md`. Primary reference: `../CLAUDE.md`.

---

Lean discovery layer for bootstrapping from raider.io top-lists (`app/Services/RaiderIO/`). **Not a full module** — DTOs do not leak past `RaiderIOSeeder`; nothing raider.io-shaped is persisted. Reuses existing Blizzard sync jobs end-to-end (dispatch and forget).

## Architecture

`RaiderIOClient` (Laravel `Http::` + Redis token-bucket throttle, default 175/min vs raider.io's 200/min ceiling), `RaiderIOSeeder` orchestrator (`seedGuilds`, `seedRuns`), `php artisan raiderio:seed`. Generators yield rows lazily — at most ~20 rows in memory at a time.

## Phases

- **Phase 1 — Guilds.** `--phase=guilds` pulls top mythic raiding guilds via `/raiding/raid-rankings?raid={RAIDERIO_CURRENT_RAID_TIER}&difficulty=mythic`, dispatches `SyncGuildData`. `SyncGuildRoster` then dispatches `SyncCharacterData::Full` per roster member (TTL-gated on `Character.updated_at`).
- **Phase 2 — Runs.** `--phase=runs` pulls top M+ runs from `/mythic-plus/runs?season={s}&region={r}&page={N}` (20 runs/page; `--limit` is *pages*). Each run yields 5 character refs; per-member `SyncCharacterData::Full`. Dedupe via `seeded_runs` table on `keystone_run_id` (immutable).
- **Phase 3 — Cancelled.** raider.io has no public per-character/per-spec ranking endpoint. To broaden coverage, bump `RAIDERIO_SEED_GUILDS_PER_REGION` / `RAIDERIO_SEED_RUNS_PAGES_PER_REGION`.

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
