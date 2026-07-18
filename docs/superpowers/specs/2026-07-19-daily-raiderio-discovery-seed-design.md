# Daily raider.io discovery seed — design

**Date:** 2026-07-19
**Status:** Approved
**Goal:** Maximize the intake of NEW characters (raids + M+) by running the existing raider.io seeder on a daily schedule, with teammate fan-out. Known characters refresh on a rolling weekly basis.

## Context

The RaiderIO seeder (`app/Services/RaiderIO/RaiderIOSeeder.php`, `php artisan raiderio:seed`) already does the right thing shape-wise:

- **Phase 1 (guilds):** top mythic raiding guilds per region → `SyncGuildData` with `forceRosterFanout` → Full Blizzard sync per endgame roster member, TTL-gated per character.
- **Phase 2 (runs):** top M+ runs per region → Full sync per run member (5 per run), TTL-gated per character. Optional recursive teammate crawl per seeded member.

It is manual-only today, tuned for one-off bootstrapping (24h char TTL, 10 guilds / 5 run-pages per region), and phase 2's `seeded_runs` ledger skips previously-seen runs *entirely* — members included — so members of a ledgered run are never re-dispatched even when stale or missing.

Discovery-per-API-call is dominated by run pages (each new run = up to 5 character refs; the ledger makes deep pulls self-limiting: a known run costs one ledger lookup and no dispatches). New characters have no `Character` row, so no TTL gate ever blocks them — every knob below widens the funnel without re-sync waste.

## Decisions (from brainstorming)

| Question | Decision |
|---|---|
| Scale | 25 guilds/region, 25 run-pages (=500 runs)/region, eu+us |
| Teammates | Built-in fan-out (rosters + run members) **plus** recursive teammate crawl for run-member seeds (depth 1, ≤10/seed, 7d recency skip) |
| Freshness | Skip characters synced within the last 7 days (`RAIDERIO_SEED_CHAR_TTL=604800`) |
| Cadence | Daily, 01:00 UTC |

## Changes

### 1. Code: lift the member loop out of the ledger skip (`RaiderIOSeeder::seedRuns`)

Keep the `seeded_runs` run-level dedupe (run data is immutable; it caps pagination cost), but run the per-member dispatch loop even for already-ledgered runs. `characterIsFresh()` (7d TTL) remains the per-member gate. Rationale: a ledgered run can still contain a member we never successfully synced (queue hiccup, prior TTL) or one now stale past a week.

~10 lines. The `insertOrIgnore` ledger write stays as-is for new runs.

### 2. Scheduler: daily entry (`bootstrap/app.php`)

```php
$schedule->command('raiderio:seed --phase=all')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();
```

01:00 UTC sits before the hourly stats warms' heavy window and the Sunday 03:00 game-data sync. `--phase=all` is required — the command errors on a missing/empty `--phase` (allowed: `guilds|runs|all`); `all` runs guilds then runs with config defaults.

### 3. Prod env (`/srv/dakis/secrets/guild-service-v2.env`)

```
RAIDERIO_SEED_CHAR_TTL=604800
RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED=true
RAIDERIO_SEED_GUILDS_PER_REGION=25
RAIDERIO_SEED_RUNS_PAGES_PER_REGION=25
```

No config-file changes — all four are existing env-backed knobs.

## Load expectations

- **raider.io:** ~2 requests/region for guild rankings + 25/region for runs ≈ 55 requests/day. Trivial against the keyed 900/min local throttle.
- **Blizzard, first night:** ~25 guilds × 2 regions × ~80–100 endgame members + up to 5,000 run refs, minus overlap/known → est. 6–9k Full syncs, draining over ~4–6h at the ~1,500 Full syncs/hr budget on `blizzard-background` / `blizzard-roster-sync`.
- **Steady state:** run-list churn + ~1/7th of the standing set per day — a small fraction of night one.
- Existing safeties apply unchanged: request-level throttle (8 req/s), circuit breaker, roster fan-out stagger (30 jobs/min), teammate-crawl caps (depth ≤1, ≤10/seed, 7d recency skip), `retryUntil` 24h.

## Interactions worth knowing

- Both phases share the `raiderio.character_resync_ttl` config key (`RaiderIOSeeder::characterIsFresh` and `SyncGuildRoster`'s fresh-tuple gate), so the weekly rule is one env var.
- Guild-level skip uses `Guild::isRosterStale()` — prod `BLIZZARD_STALE_GUILD_ROSTER=604800` already matches the weekly window.
- Teammate crawl applies to **phase 2 seeds only** (`forceTeammateCrawl` rides `SyncCharacterData`); phase 1 fan-out goes through `SyncGuildRoster`, which doesn't carry the override. Top raiders' teammates still enter via phase 2 when they appear in top runs.
- `BLIZZARD_CRAWL_RECENT_THRESHOLD` (teammate-crawl skip) already defaults to 604800 — consistent with the weekly window.

## Testing

- Feature test on `RaiderIOSeeder::seedRuns`: a run already in `seeded_runs` still dispatches `SyncCharacterData` for members that are missing or stale past the TTL, and skips fresh members.
- Existing `RaiderIOSeedCommand*` tests keep covering phases/TTL/force paths; adjust any that assert the old skip-members-with-run behavior.
- Schedule registration: assert `raiderio:seed` is scheduled (matching however existing scheduled commands are tested, if at all).

## Out of scope

- Enabling `raiderio:crawl-runs` (`RAIDERIO_CRAWL_ENABLED` stays unset): it refreshes run data for already-tracked characters and only records teammate *names* via `RunTeamPersister` — indirect discovery at best.
- New orchestrator command; exposing `raiderio_score`/`raiderio_url`; page-offset rotation for deeper rankings (future knob if 25 pages ever feels thin).
