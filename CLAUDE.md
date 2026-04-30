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
  - **Namespace per client.** `BlizzardProfileClient` sends `namespace=profile-{region}`; `BlizzardGameDataClient` sends `namespace=dynamic-{region}`. Both character *and guild* profile endpoints (`/data/wow/guild/...`, `/profile/wow/character/...`) require `profile-{region}` — do not override. Game-data **dynamic** endpoints (mythic-keystone seasons, leaderboards) use `namespace=dynamic-{region}`; game-data **static** endpoints (achievements, mounts, titles, reputation-factions, talent trees, items, playable-race) use `namespace=static-{region}` — see `BlizzardGameDataClient::getTalentTree()` and `getFactionIndex()/getFaction()` for the static-namespace pattern (bypasses `request()` and calls `Http` directly).
- **Jobs/** — All implement `ShouldQueue` + `ShouldBeUnique` (60s). Retries: 3 with backoff [30, 120, 300]s. Each job uses two middleware: `BlizzardRateLimiter` (Redis throttle, 80 req/s) and `BlizzardHealthCheck` (circuit breaker via cache flag).
- **Mappers/** — Transform raw Blizzard API JSON arrays into readonly DTOs. One mapper per data type.
- **DTO/** — Readonly classes with constructor promotion. Carry only the fields we need from Blizzard's deeply nested responses.
- **Equipment shape.** `EquippedItem` and the persisted `equipment` JSONB carry the Wowhead-ready fields: `id, name, quality, slot, item_level, bonus: int[], gems: int[], enchantments: int[], set_id: ?int, stats: [{type,value,is_negated}]`. The frontend's `WowheadLink.vue` consumes this shape directly — do not transform in controllers.
- **Talent shape.** `talents` JSONB has four branches: `class`, `spec`, `hero`, `pvp`. Retail only; Classic does not populate `talents`. The Blizzard-provided `talent_loadout_code` is a separate top-level column on `characters`, not nested in the JSONB. The source response nests talents as `specializations.specializations[<matches active_specialization.id>].loadouts[<is_active=true>].selected_{class,spec,hero}_talents`; `pvp_talent_slots` lives on the spec entry, not the loadout.
- **`game_version` column.** Every character row carries `game_version` ('retail' | 'classic') with unique index `(name, realm, region, game_version)`. Retail is the default; Classic persistence is gated behind a feature flag (Plan 3). `Character::scopeByIdentity()` filters `game_version='retail'` — Classic rows never match this scope.
- **Per-slice Full sync.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs nine independent slice writes (mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements) after the Standard-depth writes. Each slice wraps its own try/catch around a single `DB::transaction`, and owns a `*_synced_at` column plus a config staleness threshold. One slice failing never aborts the others; `*_synced_at` updates only on success. Plan 2 slices (mythic+, pvp, professions, raids) keep individual `BLIZZARD_SYNC_{SLICE}_ENABLED` kill-switches (default true) — flip to false to disable without a revert. Plan 4 slices (stats, titles, reputations, collections, achievements) **run unconditionally** since Plan 5 verified prod stability and removed their flags; disabling one of those now requires a code revert.
- **PvP bracket slugs are dynamic; fan-out is chunked.** `pvp-summary.brackets[].href` is the source of truth. Current patch: `2v2`, `3v3`, `blitz-{class}-{spec}` (solo-shuffle replaced by Blitz). `PvpBracketStatsMapper::extractSlug()` parses via regex — do not hardcode an enum. `BlizzardProfileClient::getCharacterPvpBracketsChunked()` caps each `Http::pool()` at 3 parallel slugs so a single Full-sync job cannot burst past the 80 req/s budget under Horizon max concurrency. `tier_name` is persisted as NULL (resolution requires a separate game-data endpoint — out of scope for Plan 2).
- **Delete-missing semantics.** `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`, `character_titles`, `character_reputations` all upsert then delete rows not present in the latest response, inside the slice's `DB::transaction`. An empty response (or 404) wipes that slice for the character — required behavior so a dropped profession, unplayed bracket, reset lockout, untrained title, or abandoned faction disappears.
- **Mythic+ per-spec is character-identity-filtered.** `mythic_plus_rating_by_spec` is `{specId => highest single-run rating}` from `/mythic-keystone-profile/season/{id}.best_runs[]`, reading `specialization.id` **only from the member whose `character.name` + `character.realm.slug` match the synced character** (otherwise party members' specs get credited with this character's rating). `mythic_plus_rating_color` stores the Blizzard-provided `#rrggbb` hex converted from the response's RGBA object.
- **Stats slice.** `stats` JSONB column on `characters` carries the Blizzard `/character-stats` payload (envelope keys `_links` and `character` stripped). Tracks freshness via `stats_synced_at`. A 404 from Blizzard writes `stats = null` and updates `stats_synced_at` (delete-missing semantics). Always-on after Plan 5 cleanup.
- **Titles slice.** `character_titles` rows carry `(title_id, name, display_string, is_selected)` where `is_selected` flags the character's currently equipped title (zero or one row per character). Display string is whatever Blizzard returns on the character `/titles` endpoint — gender-specific variants come from the game-data resolver (Plan 5 titles slice). Always-on after Plan 5 cleanup.
- **Reputations slice.** `character_reputations` rows carry `(faction_id, faction_name, standing, value, max)` with delete-missing inside `DB::transaction`. `value` is `standing.raw` (lossless cumulative rep — keeps the data round-trippable without needing Blizzard's per-tier crosswalk). `standing` is the lowercased name (`hated`..`exalted`). Always-on after Plan 5 cleanup. Paragon counts and renown levels were dropped from scope per Plan 5 spec §2.4 (outdated features).
- **Achievements slice (Plan 4).** Uses **DELETE-then-bulk-INSERT** instead of the sibling slices' `updateOrCreate` + per-row delete pattern. `SyncCharacterData::syncAchievements()` fetches `/character/{realm}/{name}/achievements`, then inside one `DB::transaction` issues `DELETE FROM character_achievements WHERE character_id = ?` followed by chunked `Model::insert($rows)` (1000 rows per chunk — well under PostgreSQL's 65535-parameter ceiling). This avoids O(N) round-trips for the 30k-row payloads max-level characters produce; achievements are append-only so per-row diff semantics buy nothing. Schema includes a `(character_id, completed_timestamp)` recency index alongside the `(character_id, achievement_id)` unique so the FE's "most recent first" sort is fast. Always-on after Plan 5 cleanup (was previously slow-ramped via the procedure in `docs/superpowers/plans/2026-04-28-character-achievements-slice.md` Task 21). Achievement category and Feats-of-Strength rendering come from the Plan 5 game-data resolver (`game_data_achievements` + `game_data_achievement_categories`).
- **Collections slice (Plan 4).** `SyncCharacterData::syncCollections()` fetches `/collections/{mounts,pets,toys}` in one parallel `Http::pool()` and writes to three sub-tables (`character_mounts`, `character_pets`, `character_toys`) inside one `DB::transaction` with delete-missing semantics. A single `collections_synced_at` column on `characters` tracks freshness; always-on after Plan 5 cleanup. Pets persist `creature_display_id` so the FE can link via Wowhead's `npc=` widget; toys persist `toy_id` for `item=` linking; mounts persist id + name + is_useable, with summon-spell enrichment from the Plan 5 game-data resolver (`game_data_mounts.summon_spell_id`).
- **Game-data factions resolver (Plan 5).** `game_data_expansions` (11 rows, seeded by `GameDataExpansionSeeder`) and `game_data_factions` (synced from `/data/wow/reputation-faction/{index,id}` in the **`static-{region}`** namespace by `php artisan blizzard:sync-game-data factions`, scheduled weekly) hydrate `CharacterReputation` via a new `faction()` `belongsTo` relation. `ReputationResource` exposes `faction.expansion.{id,name,display_order}` (via a manual `relationLoaded` + null check, since `whenLoaded` returns plain null when the belongsTo is loaded-but-null and would emit `"faction": null` instead of omitting the key). The faction → expansion mapping is a static `FACTION_TO_EXPANSION` array on `GameDataFactionMapper` (Blizzard does not expose expansion on the faction endpoint); 11 entries today, extend per patch. No feature flag — the eager-load is unconditional, and missing `game_data_factions` rows simply fall through to the FE's `Legacy` bucket (preserving pre-Plan-5 behavior).
- **Game-data titles resolver (Plan 5).** `game_data_titles` (PK id; `name_male`, `name_female` strings) is synced from `/data/wow/title/{index,id}` in the **`static-{region}`** namespace by `php artisan blizzard:sync-game-data titles` (also runs as part of the no-arg sweep). Per-title gender variants live in Blizzard's `gender_name: { male, female }` object on the detail endpoint; the mapper falls back to the gender-neutral `name` for both columns when the object is absent. `CharacterTitle::gameData()` is a `belongsTo` keyed on `title_id`; `CharacterTitleResource` exposes `game_data.{name_male,name_female}` via the same `relationLoaded` + null-check pattern as the factions resolver. The FE picks the variant by `character.gender`, falling back to `display_string` if `game_data` is missing. No feature flag.
- **Game-data mounts resolver (Plan 5).** `game_data_mounts` (synced from `/data/wow/mount/{index,id}` in the **`static-{region}`** namespace by `php artisan blizzard:sync-game-data mounts`, scheduled weekly with the rest) hydrates `CharacterMount` via a new `gameData()` `belongsTo` relation. `MountResource` exposes `game_data.{description,source_text,summon_spell_id,item_id}` via the same `relationLoaded` + null-check pattern as the factions/titles resolvers. `source_text` is a flattened `"<Type>: <Name>"` rendering of Blizzard's `source: { type, name }` object (e.g., "Drop: Onyxia", "Quest: A Mighty Steed"); type-only sources render as a single title-cased word (e.g., "Vendor"). For VENDOR-type mounts both fields are literally "Vendor" so the render reads "Vendor: Vendor" — cosmetic only. **`summon_spell_id` is always null with the current data source**: Blizzard's public `/data/wow/mount/{id}` endpoint does not expose the summon spell (the column is reserved for a future enrichment path). The FE gracefully falls through to plain-text rendering when null. No feature flag — eager-load is unconditional.
- **Game-data achievements resolver (Plan 5).** Two new tables: `game_data_achievement_categories` (~few hundred rows; `parent_id` self-reference is **not FK-constrained** because Blizzard's category index returns children before parents — same pattern as `game_data_factions.parent_faction_id`) and `game_data_achievements` (~40k rows; `name`, `description`, `category_id` FK, `points`, `is_account_wide`). Both populated by `php artisan blizzard:sync-game-data achievements` (categories phase first as the FK target for achievements; achievements phase upserts in chunks of 500 per `DB::transaction` because a single transaction over ~40k rows holds locks too long). Sync command extends `BlizzardGameDataClient` with four `static-{region}` methods (`getAchievement{Index,}`, `getAchievementCategory{Index,}`). **Unique among Plan-5 slices, this one does NOT eager-load on `CharacterResource`** — instead, a dedicated endpoint `GET /api/v1/game-data/achievements` returns the full joined catalog (~1MB JSON) once per session with `Cache-Control: public, max-age=86400` + `ETag`-based 304 revalidation (`GameDataController::achievements()`). The FE caches via TanStack Query (`useGameDataAchievements`, `staleTime: 24h`) and resolves names + categories client-side in `AchievementsList.vue`. Feats-of-Strength achievements are filtered from the main list by category-name match (`'Feats of Strength'`); a checkbox in the header re-includes them. Missing-row fallback: when an `achievement_id` is absent from the catalog map, the FE renders the legacy `Achievement {id}` label. No feature flag.
- **ProactiveSyncCharacters tier 1 dispatches Full.** Tier 1 (popular chars, every 30 min) refreshes all slices. Tier 2 (long-tail, every 2h) stays Standard. For on-demand backfill of specific characters that never hit either tier (e.g., roster imports), run `artisan blizzard:backfill-slices --limit=N` which dispatches Full for any retail character with any null `*_synced_at`.
- **TokenManager** — Caches OAuth client-credentials tokens per region with double-check locking to prevent thundering herd on refresh.

### Sync Depth

The `SyncDepth` enum controls how much data a character sync fetches:
- **Shallow**: basic profile only (used for roster members)
- **Standard**: profile + media + equipment + specializations
- **Full**: standard + mythic+ dungeon runs + mythic+ rating + pvp brackets + professions + raid encounter kills + stats + titles + reputations + collections (mounts/pets/toys) + achievements

### Auth

Sanctum token auth. Tokens issued on register/login, deleted on logout. Blizzard OAuth links a Battle.net account by exchanging an auth code, then dispatching `SyncUserCharacters` to import all the user's characters. Authorization uses policies (e.g., `CharacterPolicy` checks `user_id` ownership).

### Queue Layout

Jobs are dispatched to named queues with priority ordering in Horizon:
- `blizzard-auth` (highest) — token refresh
- `blizzard-user-sync` (high) — user-initiated lookups
- `blizzard-roster-sync` (medium) — guild roster fan-out
- `blizzard-background` (low) — proactive sync

### Staleness Model

Models (`Character`, `Guild`) have `isStale()` / `isRosterStale()` methods comparing `synced_at` timestamps against configurable thresholds in `config/blizzard.php`. `Character` additionally has per-slice helpers: `isMythicsStale()`, `isPvpStale()`, `isProfessionsStale()`, `isRaidsStale()`, `isStatsStale()`, `isTitlesStale()`, `isReputationsStale()`, `isCollectionsStale()`, `isAchievementsStale()`. `CharacterService::getByIdentity()` dispatches `SyncDepth::Full` when **any** slice is stale (or when its `$forceRefresh` arg is true), falling back to `SyncDepth::Standard` only when profile alone is stale. The `$forceRefresh` arg is accepted today but `?refresh=1` wiring + nonced `uniqueId` bypass of `ShouldBeUnique` is Plan 3 work — see the `TODO(Plan 3)` comment in `CharacterService`.

## Key Conventions

- **Controllers** are thin — validation in FormRequests, logic in Services, transformation in Resources.
- **Services** return models or null; controllers decide the HTTP response.
- **All Blizzard jobs** share the same structure: constructor with `readonly` params, `onQueue()` assignment, `middleware()` returning rate limiter + health check, typed `handle()` with dependency injection, `failed()` for logging.
- **DTOs** are always `readonly` with constructor promotion — no setters, no mutation.
- **API Resources** use `$this->whenLoaded('relation')` for conditional relation inclusion.
- **Model query scopes**: `byIdentity($name, $realm, $region)`, `recentlySearched($limit)`, `mostPopular($limit)`.
- **Routes** are versioned under `/api/v1/` in `routes/api.php`.
- **Config** lives in `config/blizzard.php` with env-backed values for all thresholds, timeouts, and rate limits.
