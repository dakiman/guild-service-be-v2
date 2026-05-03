# CLAUDE.md

Guidance for Claude Code when working in this repo.

Cross-repo context (current expansion, not-in-production status, test characters) lives in the project-root `../CLAUDE.md`.

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
docker compose restart horizon   # required after job/mapper/client edits;
                                 # container runs PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
```

Tests use SQLite in-memory with queue=sync, cache=array (see phpunit.xml).

## Architecture

Laravel 13 / PHP 8.4 API for WoW character and guild lookups. Data is fetched from Blizzard, cached in PostgreSQL, kept fresh via Horizon-managed queue jobs.

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

Self-contained module with `BlizzardServiceProvider`:

- **Client/** — HTTP clients extend abstract `BlizzardClient`; tokens via `TokenManager`. Retry: never on 4xx (except 429 which honors `Retry-After`), always on 5xx/timeout, max 3. `BlizzardProfileClient` uses `Http::pool()` for parallel character requests.
  - **Namespace per endpoint family.** `BlizzardProfileClient` → `profile-{region}`. Both character *and guild* profile endpoints (`/data/wow/guild/...`, `/profile/wow/character/...`) require `profile-{region}` — do not override. Game-data **dynamic** endpoints (mythic-keystone seasons/leaderboards/dungeons) → `dynamic-{region}`. Game-data **static** endpoints (achievements, mounts, titles, reputation-factions, talent trees, items, playable-race, journal-instance, keystone-affix) → `static-{region}` — see `BlizzardGameDataClient::getTalentTree()` and `getFactionIndex()/getFaction()` (bypass `request()`, call `Http` directly).
- **Jobs/** — `ShouldQueue` + `ShouldBeUnique` (60s). Retries: 3 with backoff [30, 120, 300]s. Middleware: `BlizzardRateLimiter` (Redis throttle, 80 req/s) + `BlizzardHealthCheck` (cache-flag circuit breaker).
- **Mappers/** — Transform raw Blizzard JSON arrays into readonly DTOs. One mapper per data type.
- **DTO/** — Readonly with constructor promotion; carry only fields we use.
- **Equipment shape.** `EquippedItem` and persisted `equipment` JSONB use Wowhead-ready fields: `id, name, quality, slot, item_level, bonus: int[], gems: int[], enchantments: int[], set_id: ?int, stats: [{type,value,is_negated}]`. FE's `WowheadLink.vue` consumes directly — do not transform in controllers.
- **Talent shape.** `talents` JSONB has four branches: `class`, `spec`, `hero`, `pvp`. Retail only; Classic does not populate. `talent_loadout_code` is a separate top-level column on `characters`, not in the JSONB. Source: `specializations.specializations[<matches active_specialization.id>].loadouts[<is_active=true>].selected_{class,spec,hero}_talents`; `pvp_talent_slots` lives on the spec entry, not the loadout.
- **`game_version` column.** Every character row carries `game_version` ('retail'|'classic'); unique index `(name, realm, region, game_version)`. Retail is default; Classic persistence gated behind a Plan 3 flag. `Character::scopeByIdentity()` filters `game_version='retail'` — Classic rows never match.
- **Per-slice Full sync.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs nine independent slices (mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements) after Standard writes. Each slice wraps its own try/catch around one `DB::transaction`, owns a `*_synced_at` column + config staleness threshold. One slice failing never aborts others; `*_synced_at` updates only on success. Plan 2 slices (mythic+, pvp, professions, raids) keep `BLIZZARD_SYNC_{SLICE}_ENABLED` kill-switches (default true). Plan 4 slices (stats, titles, reputations, collections, achievements) **run unconditionally** — Plan 5 cleanup removed their flags; disabling now requires a code revert.
- **PvP bracket slugs are dynamic; fan-out chunked.** `pvp-summary.brackets[].href` is source of truth. Current patch: `2v2`, `3v3`, `blitz-{class}-{spec}` (Blitz replaced solo-shuffle). `PvpBracketStatsMapper::extractSlug()` parses via regex — do not hardcode an enum. `BlizzardProfileClient::getCharacterPvpBracketsChunked()` caps each `Http::pool()` at 3 parallel slugs so a Full-sync job cannot burst past 80 req/s under Horizon max concurrency. `tier_name` persisted as NULL (resolution needs a separate game-data endpoint — out of scope for Plan 2).
- **Delete-missing semantics.** `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`, `character_titles`, `character_reputations` upsert then delete rows absent from the latest response, inside the slice's `DB::transaction`. Empty/404 wipes that slice — required so dropped professions, unplayed brackets, reset lockouts, untrained titles, abandoned factions disappear.
- **Mythic+ per-spec is character-identity-filtered.** `mythic_plus_rating_by_spec` is `{specId => highest single-run rating}` from `/mythic-keystone-profile/season/{id}.best_runs[]`, reading `specialization.id` **only from the member whose `character.name` + `character.realm.slug` match the synced character** (else party members' specs get credited with this character's rating). `mythic_plus_rating_color` is the Blizzard-provided RGBA converted to `#rrggbb`.
- **Mythic+ team pivot writes bypass `BelongsToMany`.** `dungeon_run_members` is keyed by the unique tuple `(dungeon_run_id, character_name, character_realm, character_region)`, **not** by `character_id` — `character_id` is a nullable secondary FK that resolves to a row in `characters` only when the teammate is one we already track. `SyncCharacterData::syncMythicPlus()` writes this pivot via `DB::table('dungeon_run_members')->updateOrInsert([...unique key cols...], [...])` rather than `BelongsToMany::syncWithoutDetaching`, because Eloquent's pivot upsert is character_id-keyed and (a) silently overwrites multiple unknown teammates onto a single row when given a fallback id, and (b) hits SQLSTATE[23505] when two synced characters share a run with an unknown member. The unknown teammate's `character_id` stays NULL — never falls back to the syncing character's id. Delete-missing semantics within the run match the rest of the slice convention. One-shot `php artisan blizzard:repair-dungeon-run-member-character-ids` cleans up rows where pre-fix syncs left a `character_id` whose linked identity disagrees with the row's named identity.
- **Stats slice.** `stats` JSONB on `characters` carries the `/statistics` payload (envelope keys `_links`, `character` stripped). Trailing path segment is `statistics`, NOT `character-stats` (latter 404s). Freshness via `stats_synced_at`. 404 writes `stats=null` and updates `stats_synced_at` (delete-missing).
- **Plan 5 game-data resolvers (shared shape).** Synced from `/data/wow/...` in **`static-{region}`** namespace by `php artisan blizzard:sync-game-data <slice>` (also runs in the no-arg sweep, scheduled weekly). Hydrate character-side rows via a `belongsTo` relation eager-loaded in the relevant Resource. Resources expose joined fields via a manual `relationLoaded` + null check (NOT `whenLoaded`, which emits `"key": null` when belongsTo loaded-but-null instead of omitting the key). No feature flag; missing game-data rows fall through gracefully on the FE.
- **Titles slice.** `character_titles` rows: `(title_id, name, display_string, is_selected)`; `is_selected` flags currently equipped (zero or one per character). `display_string` is whatever Blizzard returns on character `/titles`; gender-specific variants come from the Plan 5 titles resolver.
- **Reputations slice.** `character_reputations` rows: `(faction_id, faction_name, standing, value, max)` with delete-missing inside `DB::transaction`. `value` = `standing.raw` (lossless cumulative — round-trippable without Blizzard's per-tier crosswalk). `standing` is lowercased name (`hated`..`exalted`). Paragon counts and renown levels were dropped per Plan 5 spec §2.4 (outdated features).
- **Achievements slice (Plan 4).** Uses **DELETE-then-bulk-INSERT**, not the sibling `updateOrCreate` + per-row delete pattern. `SyncCharacterData::syncAchievements()` fetches `/character/{realm}/{name}/achievements`, then in one `DB::transaction` issues `DELETE FROM character_achievements WHERE character_id = ?` followed by chunked `Model::insert($rows)` (1000 rows/chunk — well under PostgreSQL's 65535-parameter ceiling). Avoids O(N) round-trips for max-level characters' 30k-row payloads; achievements are append-only so per-row diff buys nothing. Schema includes `(character_id, completed_timestamp)` recency index alongside the `(character_id, achievement_id)` unique for FE's "most recent first" sort. (Previously slow-ramped per `docs/superpowers/plans/2026-04-28-character-achievements-slice.md` Task 21.) Category and Feats-of-Strength rendering come from the Plan 5 game-data resolver (`game_data_achievements` + `game_data_achievement_categories`).
- **Collections slice (Plan 4).** `SyncCharacterData::syncCollections()` fetches `/collections/{mounts,pets,toys}` in one `Http::pool()` and writes three sub-tables (`character_mounts`, `character_pets`, `character_toys`) inside one `DB::transaction` with delete-missing. Single `collections_synced_at` column tracks freshness. Pets persist `creature_display_id` (Wowhead `npc=` widget); toys persist `toy_id` (`item=`); mounts persist id + name + is_useable, with summon-spell enrichment from the Plan 5 mounts resolver.
- **Game-data factions resolver (Plan 5).** `game_data_expansions` (11 rows, `GameDataExpansionSeeder`) + `game_data_factions` (from `/data/wow/reputation-faction/{index,id}`) hydrate `CharacterReputation` via `faction()`. `ReputationResource` exposes `faction.expansion.{id,name,display_order}`. Faction → expansion mapping is a static `FACTION_TO_EXPANSION` array on `GameDataFactionMapper` (Blizzard does not expose expansion on the faction endpoint); 11 entries today, extend per patch. Missing rows fall through to FE's `Legacy` bucket.
- **Game-data titles resolver (Plan 5).** `game_data_titles` (PK id; `name_male`, `name_female`) from `/data/wow/title/{index,id}`. Per-title gender variants live in Blizzard's `gender_name: { male, female }`; mapper falls back to gender-neutral `name` for both columns when absent. `CharacterTitle::gameData()` is `belongsTo` keyed on `title_id`; `CharacterTitleResource` exposes `game_data.{name_male,name_female}`. FE picks variant by `character.gender`, falling back to `display_string` if `game_data` is missing.
- **Game-data mounts resolver (Plan 5).** `game_data_mounts` from `/data/wow/mount/{index,id}` hydrates `CharacterMount` via `gameData()`. `MountResource` exposes `game_data.{description,source_text,summon_spell_id,item_id}`. `source_text` is a flattened `"<Type>: <Name>"` from Blizzard's `source: { type, name }` (e.g. "Drop: Onyxia"); type-only sources render as a single title-cased word ("Vendor"). VENDOR-type mounts render "Vendor: Vendor" — cosmetic only. **`summon_spell_id` is always null today**: Blizzard's public `/data/wow/mount/{id}` does not expose summon spell (column reserved for future enrichment). FE falls through to plain text when null.
- **Game-data achievements resolver (Plan 5).** Two tables: `game_data_achievement_categories` (~few hundred rows; `parent_id` self-reference is **not FK-constrained** because Blizzard's category index returns children before parents — same pattern as `game_data_factions.parent_faction_id`) and `game_data_achievements` (~40k rows; `name`, `description`, `category_id` FK, `points`, `is_account_wide`). `php artisan blizzard:sync-game-data achievements` runs categories phase first (FK target), then achievements phase upserting in chunks of 500 per `DB::transaction` (a single transaction over ~40k rows holds locks too long). Sync command extends `BlizzardGameDataClient` with four `static-{region}` methods (`getAchievement{Index,}`, `getAchievementCategory{Index,}`). Tables are pure join targets — no eager-load on `CharacterResource`, no client-side catalog endpoint. See **Character achievements endpoint** below.
- **Game-data PvE resolver (Plan A — PvE tab redesign).** Four tables: `game_data_raid_instances` (PK = Blizzard journal-instance id, FK to `game_data_expansions`); `game_data_raid_encounters` (PK = journal-encounter id, FK to raid_instances cascade delete; `creature_display_id`, `portrait_url`); `game_data_mythic_keystone_dungeons` (PK = mythic-keystone dungeon id; `journal_instance_id` is a soft join key, **not** FK-constrained — older-expansion dungeons may reference an instance we did not sync); `game_data_keystone_affixes` (PK = affix id; `icon_url`). Populated by `php artisan blizzard:sync-game-data pve` (extends the same command, scheduled weekly). Sync sequence: (1) `/data/wow/journal-instance/index` + per-id detail/media → upsert raid instances + encounter rosters (encounter detail + creature-display media → boss portrait); (2) `/data/wow/mythic-keystone/season/index` resolves current season id, then `/data/wow/mythic-keystone/season/{id}.dungeons` drives per-id `/mythic-keystone/dungeon/{id}` fan-out (dungeon endpoints live in **`dynamic-{region}`** namespace, unlike the journal-instance family which is `static-{region}`); (3) `/data/wow/keystone-affix/index` + per-id detail + media. Per-instance `DB::transaction` (one per raid wrapping its encounter fan-out) — deliberate departure from Plan-5's single-mega-transaction pattern; one tx across ~30 raids × ~8 encounters × 2-3 calls each would exceed rate-limit budget. Per-id failure tolerance: 404 or thrown error on any single instance/encounter/dungeon is logged and skipped without aborting. Dungeon `media_url` is **always null** today — Blizzard does not expose a media endpoint for `/data/wow/mythic-keystone/dungeon/{id}`; column reserved. Two public endpoints (no auth) on `GameDataController`: `GET /api/v1/game-data/raid-instances?expansion=current|all` (default `current` scopes to `game_data_expansions.display_order=1`; both eager-load `encounters`) and `GET /api/v1/game-data/mythic-keystone-dungeons?season=current` (returns dungeons + the season's affixes piggybacked, ~12-16 affix rows). Both emit `Cache-Control: max-age=3600, public` (Symfony normalizes directive ordering alphabetically — semantically identical to spec's `public, max-age=3600`) per spec §2.6 — FE caches via TanStack Query `staleTime: Infinity`. Empty table → `data: []`, not error.
- **Character achievements endpoint.** `GET /api/v1/characters/{region}/{realm}/{name}/achievements` (`CharacterAchievementsController::index`) returns cursor-paginated joined rows: `{achievement_id, completed_timestamp, name, category_name}` + `meta.{total, per_page, next_cursor}`. Default per_page=100, max=200. Order: `completed_timestamp DESC NULLS LAST`, tiebreaker `achievement_id DESC`; cursor is base64url JSON `{ts, id}` so NULL-timestamp rows paginate through their own tail. Default filters out `Feats of Strength`; `?include_feats=1` re-includes. Character payload no longer carries achievements — `CharacterController` does not eager-load, `CharacterResource` does not emit. FE consumes via `useInfiniteQuery` in `AchievementsList.vue`, prefetches next page when virtualizer is within ~200px of end. Missing-row fallback (achievement_id present on character but absent from `game_data_achievements`): join returns `name=null`/`category_name=null`, FE renders `Achievement {id}`.
- **ProactiveSyncCharacters tier 1 dispatches Full.** Tier 1 (popular, every 30 min) refreshes all slices. Tier 2 (long-tail, every 2h) stays Standard. For on-demand backfill of characters that never hit either tier (e.g., roster imports), run `artisan blizzard:backfill-slices --limit=N` — dispatches Full for any retail character with any null `*_synced_at`.
- **Recursive teammate crawl.** When `SyncCharacterData` runs at `SyncDepth::Full`, `dispatchTeammateCrawl()` runs as the last statement in `handle()` and dispatches one `SyncDepth::Full` `SyncCharacterData` job per Mythic+ teammate found in the seed's persisted `dungeon_run_members` rows for the current season — onto `blizzard-background`, with `crawlDepth = $this->crawlDepth + 1`. Gated on `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` (default `false`); depth-capped via `BLIZZARD_CRAWL_MAX_DEPTH` (default `1`, hard-clamped to `2` in code); skips teammates whose `Character.updated_at` (the column `isStale()` consults — there is no top-level `synced_at` column) is fresher than `BLIZZARD_CRAWL_RECENT_THRESHOLD` seconds (default `21600` = 6h). Crawled jobs ride the same `BlizzardRateLimiter` + `BlizzardHealthCheck` middleware as user-initiated syncs and dedupe via the existing `ShouldBeUnique` (60s, region:realm:name:depth — `crawlDepth` is intentionally excluded from the unique key so a seed and a crawl targeting the same character within 60s share the API call). Crawl is `Full` so a clicked teammate has all 9 slices warmed; the depth ceiling (clamped to 2) prevents the teammate's own Full sync from re-crawling beyond `max_depth`.
- **Auto-discover guild on character sync.** When `SyncCharacterData` writes a `Guild::firstOrCreate` shell row for a character's guild, it dispatches `SyncGuildData` immediately *if* `wasRecentlyCreated` — so the guild's profile + roster populate ahead of the user's first click rather than waiting for a stale-and-refresh round-trip. ShouldBeUnique on SyncGuildData dedupes bursts when many guild members are synced concurrently. Subsequent character syncs for the same guild skip the dispatch and rely on the existing tier-2/proactive guild refresh.
- **TokenManager** — Caches OAuth client-credentials tokens per region with double-check locking against thundering herd on refresh.

### RaiderIO Seeder (`app/Services/RaiderIO/`)

Lean discovery layer for bootstrapping the database from raider.io top-lists. Not a full module — raider.io is a throwaway discovery channel only. DTOs do not leak past `RaiderIOSeeder`; nothing raider.io-shaped is persisted.

- **Architecture.** Single `RaiderIOClient` (Laravel `Http::` wrapper + Redis token-bucket throttle at 250/min, 17% under the 300/min public ceiling), one `RaiderIOSeeder` orchestrator (currently exposes `seedGuilds`; `seedRuns` and `seedCharacters` are planned for phases 2-3), one artisan command (`raiderio:seed`). Reuses existing `SyncGuildData` / `SyncCharacterData` jobs end-to-end — seeder dispatches and forgets.
- **Trigger.** Manual artisan only. No scheduler entry today; add `Schedule::command('raiderio:seed --phase=guilds')->weekly()` later if desired. Run `--dry-run` first when memory is a concern (home-server). Generators yield rows lazily — at most one raider.io page (~20 rows) in memory at a time.
- **Phase 1 (shipped): Guilds.** `php artisan raiderio:seed --phase=guilds --limit=N --regions=eu,us` pulls top mythic raiding guilds via `/guilds/static-raid-rankings`, dispatches `SyncGuildData` per guild. `SyncGuildRoster` modification then dispatches `SyncCharacterData::Full` for each roster member (TTL-gated on `Character.updated_at`). Phase-1 hardcodes the current Midnight raid slug `the-voidspire` in `RaiderIOClient::currentRaidSlug()` — bump per raid rotation.
- **Phases 2-3 (not shipped): Runs, Characters.** Specs covered in `docs/superpowers/specs/2026-05-03-raiderio-seeder-design.md`; plans pending. The `seeded_runs` table and the `topRuns` / `topCharactersBySpec` client methods do not exist yet.
- **Dedupe model (phase 1).** Guilds: existing `Guild::isRosterStale()` is reused (config/blizzard.php threshold). `--force` flag bypasses for the future "manual re-sync" UI button.
- **Roster fan-out.** `SyncGuildRoster` previously created `guild_members` shells and dispatched a `Bus::batch` of `SyncCharacterData::Shallow` jobs. Phase 1 ADDS — does not replace — a per-member `SyncCharacterData::Full` dispatch loop, gated by `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS` (default true). TTL gate reads `Character.updated_at` against `RAIDERIO_SEED_CHAR_TTL` (default 12h = 43200s). Members above `BLIZZARD_MIN_LEVEL_FOR_LOOKUP` only.
- **Teammate crawl during seed.** `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` env var is documented but **not yet wired** into the seed loop in phase 1 — phase 2 (Runs) will wire it. Independent of the global `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` flag.
- **Rate limits.** raider.io 300/min public ceiling (no API-key tier known as of 2026-05-03). Client-side throttle 250/min, blocks up to 30s for a token. 429 retried once with `Retry-After`; 5xx retried up to 3 times with backoff [1, 4, 10]s. Phpunit sets `RAIDERIO_BACKOFF_SLEEP_ENABLED=0` so retry sleeps don't slow tests; production unset → real sleeps. Blizzard-side: existing `BlizzardRateLimiter` paces cascaded jobs at 80/s, 30k/hr — ~1,500 Full character syncs/hour ceiling. Seeder may dispatch many jobs in seconds; queue drains over hours.
- **Future requirement (not phase 1).** If raider.io usage expands beyond discovery (e.g. consuming raider.io's score breakdowns, guild attendance, alt-tracking), promote `app/Services/RaiderIO/` to a full `app/RaiderIO/` module mirroring `app/Blizzard/` (Client, DTO, Mapper, Jobs, Middleware, ServiceProvider). Today's lean shape is right *only* because raider.io is discovery-only.

#### Env vars

`RAIDERIO_BASE_URL`, `RAIDERIO_RATE_PER_MINUTE` (default 250), `RAIDERIO_SEED_REGIONS` (default `eu,us`), `RAIDERIO_SEED_SEASON` (default `season-mn-1`), `RAIDERIO_SEED_GUILDS_PER_REGION` (default 10), `RAIDERIO_SEED_CHAR_TTL` (default 43200 = 12h), `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` (default false; not yet wired in phase 1), `RAIDERIO_SEED_CHUNK` (default 50), `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS` (default true).

#### Common invocations

```bash
# First run — verify on home-server with tiny budget
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu --dry-run
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu

# Default (config-driven): 10 guilds × eu,us
php artisan raiderio:seed --phase=guilds

# Force-resync a region (future "manual re-sync" UI hook)
php artisan raiderio:seed --phase=guilds --regions=eu --force
```

### Sync Depth

`SyncDepth` enum controls fetch breadth:
- **Shallow**: basic profile only (roster members)
- **Standard**: profile + media + equipment + specializations
- **Full**: standard + mythic+ runs + mythic+ rating + pvp brackets + professions + raid encounter kills + stats + titles + reputations + collections (mounts/pets/toys) + achievements

### Auth

Sanctum token auth; tokens issued on register/login, deleted on logout. Blizzard OAuth links a Battle.net account by exchanging an auth code, then dispatches `SyncUserCharacters`. Authorization via policies (e.g., `CharacterPolicy` checks `user_id`).

### Queue Layout

Horizon priority order:
- `blizzard-auth` (highest) — token refresh
- `blizzard-user-sync` (high) — user-initiated lookups
- `blizzard-roster-sync` (medium) — guild roster fan-out
- `blizzard-background` (low) — proactive sync

### Staleness Model

`Character`, `Guild` have `isStale()` / `isRosterStale()` comparing `synced_at` against thresholds in `config/blizzard.php`. `Character` adds per-slice helpers: `isMythicsStale()`, `isPvpStale()`, `isProfessionsStale()`, `isRaidsStale()`, `isStatsStale()`, `isTitlesStale()`, `isReputationsStale()`, `isCollectionsStale()`, `isAchievementsStale()`. `CharacterService::getByIdentity()` dispatches `SyncDepth::Full` when **any** slice is stale (or `$forceRefresh` is true), falling back to `Standard` only when profile alone is stale. `$forceRefresh` is accepted today but `?refresh=1` wiring + nonced `uniqueId` bypass of `ShouldBeUnique` is Plan 3 — see `TODO(Plan 3)` in `CharacterService`.

## Key Conventions

- **Controllers** thin — validation in FormRequests, logic in Services, transformation in Resources.
- **Services** return models or null; controllers decide HTTP response.
- **All Blizzard jobs** share: `readonly` constructor params, `onQueue()`, `middleware()` returning rate limiter + health check, typed `handle()` with DI, `failed()` for logging.
- **DTOs** always `readonly` with constructor promotion — no setters, no mutation.
- **API Resources** use `$this->whenLoaded('relation')` for conditional relation inclusion.
- **Model query scopes**: `byIdentity($name, $realm, $region)`, `recentlySearched($limit)`, `mostPopular($limit)`.
- **Routes** versioned under `/api/v1/` in `routes/api.php`.
- **Config** lives in `config/blizzard.php`, env-backed for all thresholds, timeouts, rate limits.
