# Pre-warm character stats cache

## Problem

`GET /api/v1/stats/characters` is served by `CharacterStatsService::getStats()`,
which wraps `computeStats()` in `Cache::remember('stats:characters', 600, …)`.
Cold compute against the current dataset (~323k endgame-active characters)
takes ~49 seconds — eight `GROUP BY` / aggregate queries over a `whereHas` OR
`whereHas` scope. Every TTL expiry, the next user to hit the endpoint eats the
full 49s.

## Goal

Make the slow path invisible to users. Pre-warm the cache on a Horizon schedule
so the cached value is refreshed before it expires. The underlying compute cost
is not addressed — that's an explicit non-goal.

## Design

### `CharacterStatsService`

- Promote the cache key and TTL to class constants
  (`CACHE_KEY = 'stats:characters'`, `CACHE_TTL = 600`).
- `getStats()` continues to use `Cache::remember` against those constants —
  first-request fallback after a fresh deploy / Redis flush still works.
- Add `warm(): void` that calls
  `Cache::put(self::CACHE_KEY, $this->computeStats(), self::CACHE_TTL)`.
  Using `put` (not `forget` + `remember`) means concurrent requests during the
  recompute always see the previous value — there's never an empty-cache
  window.

### `App\Jobs\WarmCharacterStats`

New job, not under `App\Blizzard\Jobs` — this is DB-only, no Blizzard API
involvement, so it doesn't need the rate-limiter / health-check middleware.

- `implements ShouldQueue, ShouldBeUnique`
- `onQueue('default')` — the existing `default` supervisor in
  `config/horizon.php` already has 1–2 processes.
- `public int $uniqueFor = 290;` — just under the 5-minute cadence; if a run
  hangs, the scheduler's next dispatch is dropped instead of stacking.
- `handle(CharacterStatsService $service): void { $service->warm(); }`
- No `failed()` handler beyond default logging; a missed tick just means the
  next tick re-warms.

### Scheduler

In `bootstrap/app.php`:

```php
$schedule->job(new WarmCharacterStats)->everyFiveMinutes()->withoutOverlapping();
```

5-min cadence vs 10-min TTL leaves one full cycle of headroom for a failed or
delayed run before users hit a cold cache.

## Out of scope

- `Character::endgameActive()` uses `whereHas('raidEncounterKills')` OR
  `whereHas('dungeonRuns')` — likely the bulk of the 49s. Not touching it.
- No new indexes on `class_id` / `race_id` / `active_specialization_id`.
- No materialized snapshot table.

These are the obvious follow-ups if the warm tick itself becomes a problem
(e.g., the dataset doubles and 49s creeps toward the 5-min cadence). Not
needed today.

## Testing

- Unit: `WarmCharacterStatsTest` — dispatch the job synchronously, assert
  `Cache::get('stats:characters')` is populated and matches a direct
  `getStats()` call.
- No changes to existing `CharacterStatsControllerTest` — controller behavior
  is unchanged.
