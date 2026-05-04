# CLAUDE.md

Backend guidance. Cross-repo context (current expansion, not-in-prod, test characters) lives in `../CLAUDE.md`.

## Commands

```bash
composer test                                  # full suite (clears config cache first)
./vendor/bin/phpunit tests/Unit/X.php          # single file
./vendor/bin/phpunit --filter=testMethodName   # single method
./vendor/bin/pint [--test]                     # style fix / check
docker compose exec app php artisan <cmd>
docker compose restart horizon                 # REQUIRED after job/mapper/client edits
                                               # (PHP_OPCACHE_VALIDATE_TIMESTAMPS=0)
```

Tests use SQLite in-memory, queue=sync, cache=array (see `phpunit.xml`).

## Architecture

Laravel 13 / PHP 8.4 API for WoW character + guild lookups. Blizzard → fetch → cache in PostgreSQL → kept fresh by Horizon-scheduled jobs.

### Request flow

User-initiated lookup (`Service::getByIdentity()`):
- found & fresh → 200 + Resource
- found & stale → 200 + Resource + dispatch sync + `X-Data-Staleness: stale`
- not found → dispatch sync, return 202 + `Retry-After`

Background: `Scheduler → ProactiveSyncCharacters/Guilds → fan out per-entity SyncCharacterData / SyncGuildData`.

### Blizzard module (`app/Blizzard/`)

Self-contained, registered via `BlizzardServiceProvider`.

**Client/** — extends abstract `BlizzardClient`; tokens via `TokenManager` (per-region, double-check-locked refresh). Retry: never on 4xx (except 429 → honor `Retry-After`); always on 5xx/timeout, max 3. `BlizzardProfileClient` parallelizes via `Http::pool()`.

**Namespace per endpoint family — easy to get wrong:**
- `profile-{region}` → character profile *and* guild profile (`/data/wow/guild/...` + `/profile/wow/character/...`).
- `dynamic-{region}` → mythic-keystone seasons / leaderboards / dungeon detail.
- `static-{region}` → game-data (achievements, mounts, titles, factions, talents, items, races, journal-instance, keystone-affix). `BlizzardGameDataClient::getTalentTree()` and `getFactionIndex/getFaction` bypass `request()` and call `Http` directly.

**Jobs/** — `ShouldQueue` + `ShouldBeUnique` (60s). Retries 3 with backoff [30, 120, 300]s. Middleware: `BlizzardRateLimiter` (Redis throttle, 80 req/s) + `BlizzardHealthCheck` (cache-flag circuit breaker).

**Mappers/** — raw Blizzard JSON → readonly DTOs, one per data type. **DTOs** are readonly w/ constructor promotion; only fields we use.

### Persisted shapes (load-bearing for FE)

- **Equipment.** `EquippedItem` and `equipment` JSONB are Wowhead-ready: `id, name, quality, slot, item_level, bonus: int[], gems: int[], enchantments: int[], set_id: ?int, stats: [{type,value,is_negated}]`. FE's `WowheadLink.vue` consumes directly — do **not** transform in controllers.
- **Talents.** `talents` JSONB has four branches: `class`, `spec`, `hero`, `pvp`. Retail only. `talent_loadout_code` is its own top-level column on `characters`. Source: `specializations.specializations[<active spec>].loadouts[<is_active>].selected_{class,spec,hero}_talents`; `pvp_talent_slots` lives on the spec entry, not the loadout.
- **`game_version` column.** `'retail'|'classic'` on every character; unique index `(name, realm, region, game_version)`. `Character::scopeByIdentity()` filters retail — Classic rows never match. Classic persistence is gated behind a Plan 3 flag.

### Per-slice Full sync

`SyncCharacterData::handle()` on `SyncDepth::Full` runs nine independent slices after Standard writes: mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements. Each slice:
- Has its own try/catch around one `DB::transaction`.
- Owns a `*_synced_at` column + config staleness threshold.
- One slice failing **never** aborts the others; `*_synced_at` is updated only on success.

Plan 2 slices (mythic+, pvp, professions, raids) have `BLIZZARD_SYNC_{SLICE}_ENABLED` kill-switches (default true).
Plan 4 slices run unconditionally (Plan 5 cleanup removed flags) **except** `achievements` and `pets`, re-flagged behind `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED` / `BLIZZARD_SYNC_PETS_ENABLED` (both default false; achievements alone ≈ 70% of total DB).

**Delete-missing inside the slice's transaction** for: `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`, `character_titles`, `character_reputations`. Empty/404 → wipe — required so dropped professions, unplayed brackets, reset lockouts, untrained titles, abandoned factions disappear.

### Slice gotchas

- **PvP bracket slugs are dynamic.** `pvp-summary.brackets[].href` is source of truth. Current patch: `2v2`, `3v3`, `blitz-{class}-{spec}` (Blitz replaced solo-shuffle). `PvpBracketStatsMapper::extractSlug()` regex-parses — do **not** hardcode an enum. `getCharacterPvpBracketsChunked()` caps each `Http::pool()` at 3 parallel slugs so a Full-sync job can't burst past 80 req/s under Horizon max concurrency. `tier_name` persisted as NULL.
- **Mythic+ per-spec is character-identity-filtered.** `mythic_plus_rating_by_spec` reads `specialization.id` from `best_runs[].members[]` **only for the member matching the synced character's name + realm slug** (else teammates' specs get credited with this character's rating). `mythic_plus_rating_color` is Blizzard's RGBA → `#rrggbb`.
- **Mythic+ team pivot bypasses `BelongsToMany`.** `dungeon_run_members` unique key is `(dungeon_run_id, character_name, character_realm, character_region)`, **not** `character_id` (which is a nullable secondary FK, only set when the teammate is one we already track). `SyncCharacterData::syncMythicPlus()` writes via `DB::table()->updateOrInsert([...unique cols...], [...])` — Eloquent's pivot upsert is `character_id`-keyed and (a) silently overwrites multiple unknown teammates onto one row, (b) hits SQLSTATE[23505] when two synced characters share a run with an unknown member. Unknown teammate's `character_id` stays NULL — never falls back to the syncing character's id. Repair tool: `php artisan blizzard:repair-dungeon-run-member-character-ids`.
- **Stats slice.** `stats` JSONB carries the `/statistics` payload (envelope `_links`, `character` stripped). Path segment is `statistics`, NOT `character-stats` (latter 404s). 404 writes `stats=null` and updates `stats_synced_at`.
- **Achievements slice — DELETE-then-bulk-INSERT.** Not the sibling `updateOrCreate` + per-row delete pattern. One `DB::transaction` issues `DELETE WHERE character_id=?` then chunked `Model::insert($rows)` (1000 rows/chunk, under PG's 65535-param ceiling). Achievements are append-only and max-level chars carry ~30k rows — per-row diff buys nothing. Schema has `(character_id, completed_timestamp)` recency index alongside the `(character_id, achievement_id)` unique. Category + Feats-of-Strength rendering joins the Plan 5 game-data tables.
- **Collections slice.** Single `Http::pool()` for `/collections/{mounts,pets,toys}` → three sub-tables (`character_mounts`, `character_pets`, `character_toys`), one `DB::transaction`, delete-missing. Single `collections_synced_at`. Pets persist `creature_display_id` (Wowhead `npc=`); toys persist `toy_id` (`item=`); mounts persist id+name+is_useable, with summon-spell enrichment from Plan 5.

### Plan 5 game-data resolvers

`/data/wow/...` in **`static-{region}`** namespace, synced by `php artisan blizzard:sync-game-data <slice>` (no-arg sweep runs weekly). Hydrate character-side rows via `belongsTo` eager-load in the relevant Resource. Resources expose joined fields via manual `relationLoaded` + null check — **NOT `whenLoaded`**, which emits `"key": null` when belongsTo is loaded-but-null instead of omitting the key. No feature flag; missing rows fall through.

- **Titles** (`game_data_titles`, PK id, `name_male`, `name_female`). Falls back to gender-neutral `name` when `gender_name` absent. FE picks variant by `character.gender`, falls back to `display_string` if `game_data` missing.
- **Mounts** (`game_data_mounts`). `MountResource` exposes `game_data.{description, source_text, summon_spell_id, item_id}`. `source_text` is flattened `"<Type>: <Name>"`. **`summon_spell_id` is always null today** — Blizzard's public `/mount/{id}` doesn't expose it (column reserved).
- **Factions** (`game_data_expansions` 11 rows + `game_data_factions`). `ReputationResource` exposes `faction.expansion.{id,name,display_order}`. `FACTION_TO_EXPANSION` is a static array on the mapper (Blizzard doesn't expose it on the endpoint); 11 entries today, extend per patch. Missing → FE Legacy bucket.
- **Achievements** (`game_data_achievement_categories` few hundred rows, `game_data_achievements` ~40k). `parent_id` self-ref on categories is **not FK-constrained** because index returns children before parents. Sync runs categories first, then achievements upserting in chunks of 500 per `DB::transaction` (single tx over 40k holds locks too long). Pure join targets — no eager-load on `CharacterResource`. See *Character achievements endpoint* below.
- **PvE (Plan A redesign)** — Four tables: `game_data_raid_instances` (PK = journal-instance id, FK to expansions); `game_data_raid_encounters` (PK = journal-encounter id, FK cascade; `creature_display_id`, `portrait_url`); `game_data_mythic_keystone_dungeons` (`journal_instance_id` is **soft join key, not FK** — older-expansion dungeons may reference an instance we didn't sync); `game_data_keystone_affixes`. Synced by `blizzard:sync-game-data pve`, weekly. Per-instance `DB::transaction` (one per raid wrapping its encounters) — single mega-transaction across ~30 raids × ~8 encounters × 2-3 calls would blow rate budget. 404/error on any single instance/encounter/dungeon is logged and skipped. Dungeon endpoints live in **`dynamic-{region}`** (unlike the journal-instance family in `static-`). Dungeon `media_url` comes from **raider.io** — Blizzard has no media endpoint for `/mythic-keystone/dungeon/{id}`. Backfill: `php artisan dungeons:backfill-icons-from-raiderio --expansion=11`. Idempotent.

### Public endpoints

- `GET /api/v1/game-data/raid-instances?expansion=current|all` — eager-loads `encounters`. `current` resolves to `display_order=1`.
- `GET /api/v1/game-data/mythic-keystone-dungeons?season=current` — returns dungeons + the season's affixes piggybacked (~12-16 affix rows).

Both: no auth, no `data` envelope, `Cache-Control: max-age=3600, public`. FE caches with TanStack Query `staleTime: Infinity`. Empty table → `data: []`, not error.

- `GET /api/v1/characters/{region}/{realm}/{name}/achievements` (`CharacterAchievementsController::index`) — cursor-paginated joined rows: `{achievement_id, completed_timestamp, name, category_name}` + `meta.{total, per_page, next_cursor}`. Default per_page=100, max=200. Order: `completed_timestamp DESC NULLS LAST`, tiebreaker `achievement_id DESC`; cursor is base64url JSON `{ts, id}` so NULL-timestamp rows paginate through their own tail. Default filters `Feats of Strength`; `?include_feats=1` re-includes. Character payload no longer carries achievements (`CharacterController` doesn't eager-load, `CharacterResource` doesn't emit). Missing-row fallback: join returns `name=null`/`category_name=null`, FE renders `Achievement {id}`.

### Sync orchestration

- **ProactiveSyncCharacters tier 1 dispatches Full.** Tier 1 (popular, every 30 min) refreshes all slices. Tier 2 (long-tail, every 2h) stays Standard. On-demand backfill: `php artisan blizzard:backfill-slices --limit=N` dispatches Full for any retail character with any null `*_synced_at`.
- **Recursive teammate crawl.** On `SyncDepth::Full`, `dispatchTeammateCrawl()` runs as the last statement in `handle()` and dispatches one `Full` `SyncCharacterData` per Mythic+ teammate found in the seed's persisted `dungeon_run_members` for the current season — onto `blizzard-background`, with `crawlDepth = $this->crawlDepth + 1`. Gated on `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` (default `false`); depth ceiling `BLIZZARD_CRAWL_MAX_DEPTH` (default 1, hard-clamped to 2). Skips teammates whose `Character.updated_at` is fresher than `BLIZZARD_CRAWL_RECENT_THRESHOLD` (default 21600 = 6h). Same rate-limit + health-check middleware as user-initiated. `ShouldBeUnique` key = `region:realm:name:depth` — `crawlDepth` is intentionally excluded so a seed and a crawl targeting the same character within 60s share the API call.
- **Auto-discover guild.** When `SyncCharacterData` writes a `Guild::firstOrCreate` shell, it dispatches `SyncGuildData` if `wasRecentlyCreated` — guild profile + roster populate ahead of the user's first click. `ShouldBeUnique` dedupes bursts.

### RaiderIO seeder (`app/Services/RaiderIO/`)

Lean discovery layer for bootstrapping from raider.io top-lists. **Not a full module** — DTOs do not leak past `RaiderIOSeeder`; nothing raider.io-shaped is persisted. Reuses existing Blizzard sync jobs end-to-end (dispatch and forget).

- **Architecture.** `RaiderIOClient` (Laravel `Http::` + Redis token-bucket throttle, default 175/min vs raider.io's 200/min ceiling), `RaiderIOSeeder` orchestrator (`seedGuilds`, `seedRuns`), `php artisan raiderio:seed`. Generators yield rows lazily — at most ~20 rows in memory at a time.
- **Phase 1 — Guilds.** `--phase=guilds` pulls top mythic raiding guilds via `/raiding/raid-rankings?raid={RAIDERIO_CURRENT_RAID_TIER}&difficulty=mythic`, dispatches `SyncGuildData`. `SyncGuildRoster` then dispatches `SyncCharacterData::Full` per roster member (TTL-gated on `Character.updated_at`).
- **Phase 2 — Runs.** `--phase=runs` pulls top M+ runs from `/mythic-plus/runs?season={s}&region={r}&page={N}` (20 runs/page; `--limit` is *pages*). Each run yields 5 character refs; per-member `SyncCharacterData::Full`. Dedupe via `seeded_runs` table on `keystone_run_id` (immutable).
- **Phase 3 — Cancelled.** raider.io has no public per-character/per-spec ranking endpoint. To broaden coverage, bump `RAIDERIO_SEED_GUILDS_PER_REGION` / `RAIDERIO_SEED_RUNS_PAGES_PER_REGION`.
- **Roster fan-out is opt-in per dispatch (default OFF globally).** `SyncGuildRoster` dispatches per-member `SyncCharacterData::Full` only when (a) `forceFanout: true` was passed (seeder sets this), or (b) `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS=true` globally. So routine `ProactiveSyncGuilds` and user-initiated `SyncGuildData` do NOT cascade per-member. Members above `BLIZZARD_MIN_LEVEL_FOR_LOOKUP` only.
- **Teammate crawl override.** `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` (default false) overrides the global flag for seed-originated dispatches. Plumbed via `bool $forceTeammateCrawl` ctor param on `SyncCharacterData`. Crawled descendants get `false` (override doesn't recurse). Used by phases 2/3, not phase 1 (guild fan-out runs from `SyncGuildRoster`, not the seeder).
- **Rate limits.** raider.io 200/min public; `RAIDERIO_ACCESS_KEY` (registered apps) unlocks higher rates and is appended when set. 429 retried once with `Retry-After`; 5xx retried up to 3 with backoff [1, 4, 10]s. Phpunit sets `RAIDERIO_BACKOFF_SLEEP_ENABLED=0`. Blizzard side: existing 80/s, 30k/hr ceiling — ~1500 Full character syncs/hour; queue drains over hours.
- **Future.** If usage broadens beyond discovery, promote to a full `app/RaiderIO/` module mirroring `app/Blizzard/`.

#### Common invocations

```bash
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu --dry-run
php artisan raiderio:seed --phase=guilds                          # config defaults: 10 × eu,us
php artisan raiderio:seed --phase=runs --limit=5 --regions=eu,us
php artisan raiderio:seed --phase=guilds --regions=eu --force     # bypass TTL (NOT seeded_runs ledger)
```

### Sync depth

`SyncDepth` enum:
- **Shallow** — basic profile only (roster members).
- **Standard** — profile + media + equipment + specializations.
- **Full** — Standard + 9 slices (mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements).

### Auth

Sanctum bearer tokens (issued on register/login, deleted on logout). Blizzard OAuth links a Battle.net account by exchanging an auth code, then dispatches `SyncUserCharacters`. Authorization via policies (`CharacterPolicy` checks `user_id`).

### Queue priority (Horizon)

1. `blizzard-auth` — token refresh
2. `blizzard-user-sync` — user-initiated lookups
3. `blizzard-roster-sync` — guild roster fan-out
4. `blizzard-background` — proactive sync

### Staleness model

`Character` and `Guild` have `isStale()` / `isRosterStale()` against `config/blizzard.php` thresholds. `Character` adds per-slice helpers (`isMythicsStale`, `isPvpStale`, ..., `isAchievementsStale`). `CharacterService::getByIdentity()` dispatches `Full` when **any** slice is stale (or `$forceRefresh`), `Standard` only when profile alone is stale. `?refresh=1` wiring + nonced `uniqueId` bypass of `ShouldBeUnique` is Plan 3 — see `TODO(Plan 3)` in `CharacterService`.

## Conventions

- Controllers thin — validation in FormRequests, logic in Services, transformation in Resources.
- Services return models or null; controllers decide HTTP response.
- All Blizzard jobs share: `readonly` ctor params, `onQueue()`, `middleware()` returning rate limiter + health check, typed `handle()` with DI, `failed()` for logging.
- Resources use `$this->whenLoaded('relation')` (except for the `belongsTo`-loaded-but-null trap above — use `relationLoaded` + null check).
- Common scopes: `byIdentity($name, $realm, $region)`, `recentlySearched($limit)`, `mostPopular($limit)`.
- Routes versioned under `/api/v1/` in `routes/api.php`.
- Config in `config/blizzard.php`, env-backed for thresholds, timeouts, rate limits.
