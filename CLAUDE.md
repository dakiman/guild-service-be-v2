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
docker compose restart horizon   # after code changes affecting jobs/mappers/clients
                                 # (container runs with PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
                                 #  so a restart is required to pick up edits)
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
  - `BlizzardProfileClient` uses `Http::pool()` for parallel character data requests.
  - **Namespace per client.** `BlizzardProfileClient` sends `namespace=profile-{region}`; `BlizzardGameDataClient` sends `namespace=dynamic-{region}`. Both character *and guild* profile endpoints (`/data/wow/guild/...`, `/profile/wow/character/...`) require `profile-{region}` — do not override. Game-data endpoints (season index, item, playable-race, etc.) require `dynamic-{region}`.
- **Jobs/** — All implement `ShouldQueue` + `ShouldBeUnique` (60s). Retries: 3 with backoff [30, 120, 300]s. Each job uses two middleware: `BlizzardRateLimiter` (Redis throttle, 80 req/s) and `BlizzardHealthCheck` (circuit breaker via cache flag).
- **Mappers/** — Transform raw Blizzard API JSON arrays into readonly DTOs. One mapper per data type.
- **DTO/** — Readonly classes with constructor promotion. Carry only the fields we need from Blizzard's deeply nested responses.
- **Equipment shape.** `EquippedItem` and the persisted `equipment` JSONB carry the Wowhead-ready fields: `id, name, quality, slot, item_level, bonus: int[], gems: int[], enchantments: int[], set_id: ?int, stats: [{type,value,is_negated}]`. The frontend's `WowheadLink.vue` consumes this shape directly — do not transform in controllers.
- **Talent shape.** `talents` JSONB has four branches: `class`, `spec`, `hero`, `pvp`. Retail only; Classic does not populate `talents`. The Blizzard-provided `talent_loadout_code` is a separate top-level column on `characters`, not nested in the JSONB. The source response nests talents as `specializations.specializations[<matches active_specialization.id>].loadouts[<is_active=true>].selected_{class,spec,hero}_talents`; `pvp_talent_slots` lives on the spec entry, not the loadout.
- **`game_version` column.** Every character row carries `game_version` ('retail' | 'classic') with unique index `(name, realm, region, game_version)`. Retail is the default; Classic persistence is gated behind a feature flag (Plan 3). `Character::scopeByIdentity()` filters `game_version='retail'` — Classic rows never match this scope.
- **Per-slice Full sync with feature flags.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs seven independent slice writes (mythic+, pvp, professions, raids, stats, titles, reputations) after the Standard-depth writes. Each slice is gated on `config('blizzard.sync.{slice}_enabled')` (backed by `BLIZZARD_SYNC_{SLICE}_ENABLED` env, default true for Plan 2 slices and false for Plan 4 slices), wraps its own try/catch around a single `DB::transaction`, and owns a `*_synced_at` column plus a config staleness threshold. One slice failing never aborts the others; `*_synced_at` updates only on success. Kill a misbehaving slice via env without a revert.
- **PvP bracket slugs are dynamic; fan-out is chunked.** `pvp-summary.brackets[].href` is the source of truth. Current patch: `2v2`, `3v3`, `blitz-{class}-{spec}` (solo-shuffle replaced by Blitz). `PvpBracketStatsMapper::extractSlug()` parses via regex — do not hardcode an enum. `BlizzardProfileClient::getCharacterPvpBracketsChunked()` caps each `Http::pool()` at 3 parallel slugs so a single Full-sync job cannot burst past the 80 req/s budget under Horizon max concurrency. `tier_name` is persisted as NULL (resolution requires a separate game-data endpoint — out of scope for Plan 2).
- **Delete-missing semantics.** `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`, `character_titles`, `character_reputations` all upsert then delete rows not present in the latest response, inside the slice's `DB::transaction`. An empty response (or 404) wipes that slice for the character — required behavior so a dropped profession, unplayed bracket, reset lockout, untrained title, or abandoned faction disappears.
- **Mythic+ per-spec is character-identity-filtered.** `mythic_plus_rating_by_spec` is `{specId => highest single-run rating}` from `/mythic-keystone-profile/season/{id}.best_runs[]`, reading `specialization.id` **only from the member whose `character.name` + `character.realm.slug` match the synced character** (otherwise party members' specs get credited with this character's rating). `mythic_plus_rating_color` stores the Blizzard-provided `#rrggbb` hex converted from the response's RGBA object.
- **Stats slice.** `stats` JSONB column on `characters` carries the Blizzard `/character-stats` payload (envelope keys `_links` and `character` stripped). The slice is gated on `BLIZZARD_SYNC_STATS_ENABLED` (default false) and tracks freshness via `stats_synced_at`. A 404 from Blizzard writes `stats = null` and updates `stats_synced_at` (delete-missing semantics).
- **Titles slice.** `character_titles` rows carry `(title_id, name, display_string, is_selected)` where `is_selected` flags the character's currently equipped title (zero or one row per character). Display string is whatever Blizzard returns on the character `/titles` endpoint — gender-specific variants live on the per-title game-data endpoint and are out of scope for this slice. `BLIZZARD_SYNC_TITLES_ENABLED` defaults to `false` (ramp manually per environment).
- **Reputations slice.** `character_reputations` rows carry `(faction_id, faction_name, standing, value, max)` with delete-missing inside `DB::transaction`. `value` is `standing.raw` (lossless cumulative rep — keeps the data round-trippable without needing Blizzard's per-tier crosswalk). `standing` is the lowercased name (`hated`..`exalted`). `BLIZZARD_SYNC_REPUTATIONS_ENABLED` defaults `false` — flip on to enable the slice without a code revert. Paragon counts and renown levels are deferred to a follow-up slice (require additional per-faction endpoint calls).
- **ProactiveSyncCharacters tier 1 dispatches Full.** Tier 1 (popular chars, every 30 min) refreshes all slices. Tier 2 (long-tail, every 2h) stays Standard. For on-demand backfill of specific characters that never hit either tier (e.g., roster imports), run `artisan blizzard:backfill-slices --limit=N` which dispatches Full for any retail character with any null `*_synced_at`.
- **TokenManager** — Caches OAuth client-credentials tokens per region with double-check locking to prevent thundering herd on refresh.

### Sync Depth

The `SyncDepth` enum controls how much data a character sync fetches:
- **Shallow**: basic profile only (used for roster members)
- **Standard**: profile + media + equipment + specializations
- **Full**: standard + mythic+ dungeon runs + mythic+ rating + pvp brackets + professions + raid encounter kills

### Auth

Sanctum token auth. Tokens issued on register/login, deleted on logout. Blizzard OAuth links a Battle.net account by exchanging an auth code, then dispatching `SyncUserCharacters` to import all the user's characters. Authorization uses policies (e.g., `CharacterPolicy` checks `user_id` ownership).

### Queue Layout

Jobs are dispatched to named queues with priority ordering in Horizon:
- `blizzard-auth` (highest) — token refresh
- `blizzard-user-sync` (high) — user-initiated lookups
- `blizzard-roster-sync` (medium) — guild roster fan-out
- `blizzard-background` (low) — proactive sync

### Staleness Model

Models (`Character`, `Guild`) have `isStale()` / `isRosterStale()` methods comparing `synced_at` timestamps against configurable thresholds in `config/blizzard.php`. `Character` additionally has per-slice helpers: `isMythicsStale()`, `isPvpStale()`, `isProfessionsStale()`, `isRaidsStale()`, `isStatsStale()`, `isTitlesStale()`, `isReputationsStale()`. `CharacterService::getByIdentity()` dispatches `SyncDepth::Full` when **any** slice is stale (or when its `$forceRefresh` arg is true), falling back to `SyncDepth::Standard` only when profile alone is stale. The `$forceRefresh` arg is accepted today but `?refresh=1` wiring + nonced `uniqueId` bypass of `ShouldBeUnique` is Plan 3 work — see the `TODO(Plan 3)` comment in `CharacterService`.

## Key Conventions

- **Controllers** are thin — validation in FormRequests, logic in Services, transformation in Resources.
- **Services** return models or null; controllers decide the HTTP response.
- **All Blizzard jobs** share the same structure: constructor with `readonly` params, `onQueue()` assignment, `middleware()` returning rate limiter + health check, typed `handle()` with dependency injection, `failed()` for logging.
- **DTOs** are always `readonly` with constructor promotion — no setters, no mutation.
- **API Resources** use `$this->whenLoaded('relation')` for conditional relation inclusion.
- **Model query scopes**: `byIdentity($name, $realm, $region)`, `recentlySearched($limit)`, `mostPopular($limit)`.
- **Routes** are versioned under `/api/v1/` in `routes/api.php`.
- **Config** lives in `config/blizzard.php` with env-backed values for all thresholds, timeouts, and rate limits.
