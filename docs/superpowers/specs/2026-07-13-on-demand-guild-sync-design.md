# On-demand guild sync (kill the background guild sweeps)

**Date:** 2026-07-13
**Status:** Approved, pending implementation

## Problem

On Sunday 2026-07-12 04:00, the weekly `ProactiveSyncGuilds` sweep dispatched
~38.7k `SyncGuildData` jobs (one per stale guild; the table has grown to 45k
rows) onto `blizzard-user-sync`. Throughput under the 8 req/s Blizzard budget
is ~2k guild syncs/hour, so 22,190 jobs aged past their 6h `retryUntil`
without ever being picked up and were reaped as `MaxAttemptsExceededException`
with `attempts: 0`. Collateral roster/teammate fan-out churned another ~20k
jobs into the same 6h wall through the evening. Total: ~48k failed jobs in one
day, none of which represent a real error.

Two background producers cause this:

1. **`ProactiveSyncGuilds`** (weekly, Sun 04:00) — dispatches every stale
   guild with no cap, stagger, or relevance filter, onto the top-priority
   user lane.
2. **Auto-discover** (the guild-link block in `SyncCharacterData::handle()`)
   — every guild shell
   created during character sync/crawl dispatches a full `SyncGuildData`,
   which unconditionally dispatches `SyncGuildRoster`, which fans out Shallow
   character syncs for every endgame roster member. This is the everyday
   amplifier that grew the table to 45k guilds (11,687 of them never synced).

Secondary issues found during diagnosis:

- `SyncGuildData` hardcodes `onQueue('blizzard-user-sync')`, so background
  sweeps starve real user lookups.
- Laravel's `MaxAttemptsExceededException` message ("attempted too many
  times") is thrown even for jobs reaped before their first run, which reads
  as retry-churn when it is actually queue starvation.
- Slice-level sync errors are swallowed-but-logged by design, but logs go to
  `storage/logs/laravel.log` inside the container, which is destroyed on
  every deploy. Incident-era logs were unrecoverable.

## Decisions (brainstormed 2026-07-13)

| Question | Decision |
|---|---|
| Auto-discover behavior | Profile + roster rows only (2 API calls), **no** member fan-out |
| User-visit cascade | Profile + roster rows only, **no** member fan-out; members become full characters when individually viewed |
| Weekly sweep | Delete `ProactiveSyncGuilds` entirely |
| Aged-out jobs | Keep `retryUntil` model, bump 6h → 24h, no other mechanism |
| Logging | `stack` = `stderr` + `daily` file on a bind mount |
| Structure | Approach A: trim existing jobs (no new job classes, no events) |

## Design

### 1. `SyncGuildData` becomes lane-aware and cascade-free

Keeps both API calls and both DB writes (guild profile upsert + atomic
roster-rows transaction) — every caller wants those. Changes:

- **New `origin` param**, following the `SyncCharacterData` pattern exactly:
  non-readonly with property-style default (`public SyncOrigin $origin =
  SyncOrigin::UserLookup`) so old-shape queued payloads unserialize safely;
  constructor calls `$this->onQueue($origin->queue())`.
- **`SyncOrigin` gains a `Discovery` case** mapping to `blizzard-background`.
- **Roster job dispatch becomes opt-in:** the tail of `handle()` changes from
  unconditional `SyncGuildRoster::dispatch(...)` to
  `if ($this->forceRosterFanout) { ... }`.
- **`forceCascade` param is deleted.** Old queued payloads carrying the
  property rehydrate with a dynamic property (PHP deprecation warning at
  worst); the queue is expected to be near-empty at deploy.
- `uniqueId()` mode segment now keys on `forceRosterFanout` alone.
- **`tags()` added** (mirroring `SyncCharacterData`): `origin:{value}` +
  `guild:{region}:{realm}:{name}` so future floods are attributable in
  Horizon.

### 2. Dispatch sites

| Site | Change |
|---|---|
| `GuildController::show` (guild unknown → 202) | `SyncGuildData::dispatch($region, $realm, $guild)` — defaults: `UserLookup` lane, no fan-out |
| `GuildService::getByIdentity` (stale on visit) | Same plain dispatch |
| `SyncCharacterData` auto-discover (`wasRecentlyCreated`) | Adds `origin: SyncOrigin::Discovery` → background lane, no fan-out |
| `RaiderIOSeeder` | Keeps `forceRosterFanout: true`, adds `origin: SyncOrigin::Discovery` — bulk seeding leaves the user lane |
| `ProactiveSyncGuilds` | Class, schedule entry (`bootstrap/app.php`), and tests deleted |

`SyncGuildRoster` itself is unchanged apart from the retry-window bump
(section 3) and is now reachable only via the seeder path
(`forceRosterFanout: true`) or the `raiderio.dispatch_roster_character_syncs`
config flag (default false).

### 3. Retry window

`retryUntil()` 6h → 24h on `SyncCharacterData`, `SyncGuildData`,
`SyncGuildRoster`. The time-bounded model stays because the rate-limiter
middleware `release()`s jobs on 429/throttle-wait, and a fixed `$tries`
budget would burn an attempt per release. Update the adjacent comments to
name the new window.

### 4. Logging

- `config/logging.php`: `stack` channel → `['stderr', 'daily']`, daily
  retention 14 days.
- Deployment (`/srv/dakis`, separate commit, all three PHP containers —
  app/horizon/scheduler): `LOG_CHANNEL=stack` env; bind mount
  `data/guild-service-v2/logs` → `/var/www/html/storage/logs` (writable by
  `www-data`).

Result: slice warnings show live in `docker logs` and survive deploys on
disk.

### 5. One-off backfill command

`php artisan guilds:backfill-shells [--limit=N] [--dry-run]`

Dispatches a plain `SyncGuildData` (origin `Discovery`) for every guild with
`roster_synced_at IS NULL` (~11.7k shells). No stagger: the background lane
drains ~23k API calls in roughly an hour at 8 req/s, well inside the 24h
window, and nothing else bulk-produces guild jobs anymore. Running it is
manual and optional — shells also fill in organically on first visit.

## Testing

- `SyncGuildData` unit tests: no `SyncGuildRoster` dispatch by default;
  dispatched when `forceRosterFanout: true`; queue lane follows `origin`;
  `uniqueId` mode segment.
- Controller/service feature tests: dispatch assertions updated for the new
  signature (no `forceCascade`).
- Auto-discover test: asserts `Discovery` origin → `blizzard-background`.
- Backfill command test with `Queue::fake` (targets only never-synced
  shells, honors `--limit`, `--dry-run` dispatches nothing).
- Delete `ProactiveSyncGuilds` tests.

## Out of scope

- Teammate-crawl tuning (`BLIZZARD_CRAWL_*`) — unchanged.
- `ProactiveSyncCharacters` tiers — unchanged.
- Rate-limit budget (8 req/s) — unchanged.
- Failed-job pruning cadence (`queue:prune-failed --hours=168` daily) —
  unchanged; the existing 53k rows age out on their own by 2026-07-20.

## Expected effect

- The Sunday flood becomes structurally impossible: nothing bulk-dispatches
  guild syncs.
- Background guild cost drops to 2 API calls per newly discovered guild.
- User lookups never compete with sweeps for `blizzard-user-sync`.
- Remaining `failed_jobs` entries are real errors worth reading.
