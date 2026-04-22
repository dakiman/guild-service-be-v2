# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Run all tests (clears config cache first)
composer test

# Run a single test file
./vendor/bin/phpunit tests/Unit/SpecificTest.php

# Run a single test method
./vendor/bin/phpunit --filter=testMethodName

# Code style (Laravel Pint)
./vendor/bin/pint          # fix
./vendor/bin/pint --test   # check only

# Dev server (concurrent: serve + queue + logs + vite)
composer dev

# Docker
docker compose up -d
docker compose exec app php artisan <command>
docker compose restart horizon   # after code changes affecting jobs
```

Tests use SQLite in-memory with queue=sync, cache=array (see phpunit.xml).

## Architecture

Laravel 13 / PHP 8.4 API for WoW character and guild lookups. Data is fetched from the Blizzard API, cached in PostgreSQL, and kept fresh via background queue jobs managed by Horizon.

### Request Flow

**User-initiated lookup** (character or guild):
```
Controller → Service::getByIdentity()
  → Model found & fresh? → return Resource (200)
  → Model found & stale? → return Resource (200) + dispatch sync job + X-Data-Staleness header
  → Model not found? → dispatch sync job → return 202 + Retry-After
```

**Background sync** (scheduled):
```
Scheduler → ProactiveSyncCharacters/Guilds job
  → queries popular/recently-searched entities
  → dispatches individual SyncCharacterData / SyncGuildData jobs
```

### Blizzard Module (`app/Blizzard/`)

Self-contained module with its own service provider (`BlizzardServiceProvider`), organized as:

- **Client/** — HTTP clients extending abstract `BlizzardClient`. Token injection via `TokenManager`. Retry logic: never retry 4xx (except 429 which respects `Retry-After`), always retry 5xx/timeouts, max 3 attempts.
  - `BlizzardProfileClient` uses `Http::pool()` for parallel character data requests
- **Jobs/** — All implement `ShouldQueue` + `ShouldBeUnique` (60s). Retries: 3 with backoff [30, 120, 300]s. Each job uses two middleware: `BlizzardRateLimiter` (Redis throttle, 80 req/s) and `BlizzardHealthCheck` (circuit breaker via cache flag).
- **Mappers/** — Transform raw Blizzard API JSON arrays into readonly DTOs. One mapper per data type.
- **DTO/** — Readonly classes with constructor promotion. Carry only the fields we need from Blizzard's deeply nested responses.
- **Equipment shape.** `EquippedItem` and the persisted `equipment` JSONB carry the Wowhead-ready fields: `id, name, quality, slot, item_level, bonus: int[], gems: int[], enchantments: int[], set_id: ?int, stats: [{type,value,is_negated}]`. The frontend's `WowheadLink.vue` consumes this shape directly — do not transform in controllers.
- **Talent shape.** `talents` JSONB has four branches: `class`, `spec`, `hero`, `pvp`. Retail only; Classic does not populate `talents`. The Blizzard-provided `talent_loadout_code` is a separate top-level column on `characters`, not nested in the JSONB.
- **`game_version` column.** Every character row carries `game_version` ('retail' | 'classic') with unique index `(name, realm, region, game_version)`. Retail is the default; Classic persistence is gated behind a feature flag (Plan 3).
- **TokenManager** — Caches OAuth client-credentials tokens per region with double-check locking to prevent thundering herd on refresh.

### Sync Depth

The `SyncDepth` enum controls how much data a character sync fetches:
- **Shallow**: basic profile only (used for roster members)
- **Standard**: profile + media + equipment + specializations
- **Full**: standard + mythic+ dungeon runs

### Auth

Sanctum token auth. Tokens issued on register/login, deleted on logout. Blizzard OAuth links a Battle.net account by exchanging an auth code, then dispatching `SyncUserCharacters` to import all the user's characters. Authorization uses policies (e.g., `CharacterPolicy` checks `user_id` ownership).

### Queue Layout

Jobs are dispatched to named queues with priority ordering in Horizon:
- `blizzard-auth` (highest) — token refresh
- `blizzard-user-sync` (high) — user-initiated lookups
- `blizzard-roster-sync` (medium) — guild roster fan-out
- `blizzard-background` (low) — proactive sync

### Staleness Model

Models (`Character`, `Guild`) have `isStale()` / `isRosterStale()` methods comparing `synced_at` timestamps against configurable thresholds in `config/blizzard.php`. Services check staleness on every lookup and dispatch refresh jobs as needed.

## Key Conventions

- **Controllers** are thin — validation in FormRequests, logic in Services, transformation in Resources.
- **Services** return models or null; controllers decide the HTTP response.
- **All Blizzard jobs** share the same structure: constructor with `readonly` params, `onQueue()` assignment, `middleware()` returning rate limiter + health check, typed `handle()` with dependency injection, `failed()` for logging.
- **DTOs** are always `readonly` with constructor promotion — no setters, no mutation.
- **API Resources** use `$this->whenLoaded('relation')` for conditional relation inclusion.
- **Model query scopes**: `byIdentity($name, $realm, $region)`, `recentlySearched($limit)`, `mostPopular($limit)`.
- **Routes** are versioned under `/api/v1/` in `routes/api.php`.
- **Config** lives in `config/blizzard.php` with env-backed values for all thresholds, timeouts, and rate limits.
