# Sync Strategy

How characters, guilds, and game data get fetched and kept fresh.

## Trigger types

| Trigger | Frequency | Depth | Queue | Notes |
|---|---|---|---|---|
| User visits character | On-demand | Standard (Full if any slice stale) | `blizzard-user-sync` | Staleness checked per-slice |
| User visits guild | On-demand | Profile + roster rows only | `blizzard-user-sync` | No member fan-out; fan-out is seeder-only via `forceRosterFanout` |
| Proactive tier 1 | Every 30 min | Full | `blizzard-background` | Characters with 5+ searches in last 7 days, active login |
| Proactive tier 2 | Every 2 hours | Standard | `blizzard-background` | Characters with 2+ searches in last 30 days |
| OAuth login | On auth | Full per character | `blizzard-user-sync` | Via `SyncUserCharacters` |
| RaiderIO seeder | Manual | Full | `blizzard-background` | `raiderio:seed --phase=guilds\|runs`; guild jobs use `origin: Discovery` |
| Backfill | Manual | Full | `blizzard-user-sync` | `blizzard:backfill-slices --limit=N` for null `*_synced_at` |
| Auto-discover guild | During any character sync | Profile + roster | `blizzard-background` | When character has a guild we don't have (`firstOrCreate`); `origin: Discovery` |
| Game data | Weekly Sun 03:00 | Reference tables | `default` | Mounts, talents, raids, factions, etc. |
| Token refresh | Twice daily | N/A | `blizzard-auth` | All regions |

## Job chain

```
User visits guild
  └─ SyncGuildData (profile + roster upsert only — no member fan-out)

RaiderIO seeder
  └─ SyncGuildData (forceRosterFanout: true)
       └─ SyncGuildRoster
            ├─ SyncCharacterData::Shallow  (per member, unconditional)
            └─ SyncCharacterData::Full     (per member, only if forceFanout or config flag)
                 └─ dispatchTeammateCrawl   (M+ teammates, depth-capped at 2)

User visits character
  └─ SyncCharacterData::Standard  (or Full if any slice stale)
       └─ may discover guild → SyncGuildData (if firstOrCreate)

Proactive tier 1
  └─ SyncCharacterData::Full (popular characters)
```

There is no proactive guild sweep — the weekly `ProactiveSyncGuilds` job was deleted after the 2026-07-12 queue-starvation incident. Guild syncs happen only on visit, auto-discover, or seeder run.

## Sync depths

- **Shallow** — basic profile only (roster member discovery)
- **Standard** — profile + media + equipment + specializations
- **Full** — Standard + 9 slices: mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements

Each slice has independent try/catch, its own `*_synced_at` column, and its own staleness threshold. One slice failing never aborts others.

## Staleness thresholds

| Slice | Threshold | Notes |
|---|---|---|
| Profile | 15 min | |
| M+ | 30 min | |
| PvP | 30 min | |
| Equipment | 15 min | |
| Stats | 15 min | |
| Raids | 1 hour | |
| Professions | 6 hours | |
| Titles | 6 hours | |
| Reputations | 6 hours | |
| Collections | 24 hours | mounts/pets/toys |
| Achievements | 24 hours | behind feature flag, default off |
| Guild profile | 1 hour | |
| Guild roster | 2 hours | |

## Rate limiting & circuit breaker

**Redis throttle:** 80 req/s via `BlizzardRateLimiter` middleware. Jobs that can't acquire a slot are released back to queue after 10s.

**429 handling:** Blizzard 429 responses are NOT retried at the HTTP layer. The `BlizzardRateLimiter` middleware catches them, releases the job with `Retry-After` delay (non-blocking), and increments a hit counter.

**Circuit breaker:** After 10 rate-limit hits within a 2-minute window, the middleware sets `blizzard:unhealthy` in cache for 60 seconds. `BlizzardHealthCheck` middleware checks this flag and releases all Blizzard jobs during cooldown. Config: `BLIZZARD_CIRCUIT_BREAKER_THRESHOLD` (default 10).

**Job retries:** All Blizzard jobs use `$tries = 15` / `$maxExceptions = 3` / `$backoff = [30, 120, 300]`. `$tries` counts both exceptions and releases; `$maxExceptions` only counts unhandled exceptions. This gives jobs room for multiple rate-limit releases without exhausting the failure budget.

## Feature flags (heavy slices)

Collections and achievements are gated to control DB size:

| Flag | Default | Impact |
|---|---|---|
| `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED` | false | ~70% of total DB when enabled |
| `BLIZZARD_SYNC_PETS_ENABLED` | false | Part of collections HTTP pool |
| `BLIZZARD_SYNC_MOUNTS_ENABLED` | false | Part of collections HTTP pool |
| `BLIZZARD_SYNC_TOYS_ENABLED` | false | Part of collections HTTP pool |

When all three collection flags are off, `syncCollections()` early-returns without making any HTTP calls.

Exposed to FE via `CharacterResource.meta.feature_flags`. FE conditionally hides Collections tab/subtabs.

## Purging heavy slice data

```bash
php artisan blizzard:purge-heavy-slices          # purges mounts, toys, pets, achievements (respects flags)
```

## Queue workers (Horizon)

| Queue | Workers (local) | Purpose |
|---|---|---|
| `blizzard-auth` | 1 | Token refresh |
| `blizzard-user-sync` | 3-5 | User-initiated + guild syncs |
| `blizzard-roster-sync` + `blizzard-background` | 3-8 | Roster fan-out + proactive |
| `default` | 1-2 | Misc |
