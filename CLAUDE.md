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

User-initiated lookup (`CharacterService::getByIdentity()`; `GuildService` follows the same pattern):
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

**Jobs/** — `ShouldQueue` + `ShouldBeUnique` (60s). time-bounded retries (`retryUntil()` 6h + `$maxExceptions = 3`, backoff [30, 120, 300]s) — middleware `release()` re-queues without burning an attempts budget, but a flooded queue can still churn a job past its 6h window. Middleware (order matters): `BlizzardHealthCheck` (pauses all jobs for 60s when `blizzard:unhealthy` is set) **then** `BlizzardRateLimiter` (catches 429 → non-blocking `$job->release($retryAfter)` + auto-trips circuit breaker after 10 hits in 2 min; catches `BlizzardThrottleTimeoutException` → `release(10)`). Rate limiting itself is **request-level**: `BlizzardHttpThrottle` acquires one Redis slot per real HTTP request to `*.api.blizzard.com`, hooked via `Http::globalRequestMiddleware` in `BlizzardServiceProvider` — covers `request()`-built calls, `Http::pool()` fan-outs, the direct `Http` calls in `BlizzardGameDataClient`, and 5xx retries; OAuth traffic is exempt. Budget: `rate_limit.requests_per_second` (default **8**, env `BLIZZARD_RATE_LIMIT_REQUESTS_PER_SECOND`; `<= 0` disables — phpunit.xml sets 0); a request waits up to `block_seconds` (30) for a slot, then the job releases. Blizzard's real ceiling is ~100 req/s burst but 36k req/hour = 10/s sustained. Full sync strategy: `docs/sync-strategy.md`.

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

Plan 2 slices (mythic+, pvp, professions, raids) have `BLIZZARD_SYNC_{SLICE}_ENABLED` kill-switches (default true). Heavy slices (mounts, toys, achievements, pets) are also flag-gated (default false). Stats, titles, and reputations run unconditionally. Achievements alone ≈ 70% of total DB.

**Bulk upsert + delete-missing inside the slice's transaction** for: `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`. Writes go through the `BulkUpsertable` trait (`Model::upsertMany()` keyed on each model's `UNIQUE_KEY` const — one `INSERT … ON CONFLICT` instead of per-row `updateOrCreate`); composite-key slices then prune stale rows with a single id `whereIn`. Empty/404 → wipe — required so dropped professions, unplayed brackets, reset lockouts disappear. (Eloquent `upsert()` manages timestamps + casts-via-instance; see `DungeonRun::upsertRun`.)

- **Titles/reputations are JSONB columns on `characters` (2026-07-07)** — `title_ids` (int array) and `reputations` (object array `{faction_id, faction_name, standing, value, max}`), replaced whole-set on each sync (empty/404 → empty array, so untrained titles and abandoned factions still disappear). Resolved to names/faction blocks at read time via `Character::resolvedTitles()`/`resolvedReputations()` against `game_data_titles`/`game_data_factions`. The `character_titles`/`character_reputations` tables are dropped (Phase 2); one-off migration copy: `php artisan characters:backfill-jsonb-slices --dry-run`. `*_synced_at` staleness handling unchanged.

Per-slice edge cases (PvP dynamic slugs, M+ team pivot, stats path, achievements DELETE-INSERT, collections pool): `docs/slice-gotchas.md`.

- **Raids-slice retention (2026-07-07).** Background lanes (`SyncOrigin` ≠ `UserLookup`) persist only retained expansions — `RaidRetention::expansions()` = current (`display_order=1` in `game_data_expansions`) + `'Current Season'`; delete-missing is scoped to the same set so gated syncs never wipe a searched character's legacy rows. User-lane syncs persist all expansions (full history self-heals on first view). `raids:prune-legacy` (scheduled monthly, batched, `--dry-run`) deletes legacy rows of `num_of_searches = 0` characters; refuses to run when `game_data_expansions` is empty. The stats heatmap (`/stats/characters/raid-kills`) serves the current expansion only — the `expansion` query param is gone, `expansions` in the response is always `[<current>]`.

### Plan 5 game-data resolvers

`/data/wow/...` in **`static-{region}`** namespace, synced by `php artisan blizzard:sync-game-data <slice>` (no-arg sweep runs weekly). Hydrate character-side rows via `belongsTo` eager-load in the relevant Resource. Resources expose joined fields via manual `relationLoaded` + null check — **NOT `whenLoaded`**, which emits `"key": null` when belongsTo is loaded-but-null instead of omitting the key. No feature flag; missing rows fall through.

- **Titles** (`game_data_titles`, PK id, `name_male`, `name_female`). Falls back to gender-neutral `name` when `gender_name` absent. FE picks variant by `character.gender`, falls back to `display_string` if `game_data` missing. Resolved at read time from `characters.title_ids` via `Character::resolvedTitles()` (ordered by id) — no eager-load.
- **Mounts** (`game_data_mounts`). `MountResource` exposes `game_data.{description, source_text, summon_spell_id, item_id}`. `source_text` is flattened `"<Type>: <Name>"`. **`summon_spell_id` is always null today** — Blizzard's public `/mount/{id}` doesn't expose it (column reserved).
- **Factions** (`game_data_expansions` 11 rows + `game_data_factions`). `Character::resolvedReputations()` enriches each `characters.reputations` entry with `faction.expansion.{id,name,display_order}` at read time; the `faction` key is **omitted (not null)** when no game-data row exists. `FACTION_TO_EXPANSION` is a static array on the mapper (Blizzard doesn't expose it on the endpoint); 11 entries today, extend per patch. Missing → FE Legacy bucket.
- **Achievements** (`game_data_achievement_categories` few hundred rows, `game_data_achievements` ~40k). `parent_id` self-ref on categories is **not FK-constrained** because index returns children before parents. Sync runs categories first, then achievements upserting in chunks of 500 per `DB::transaction` (single tx over 40k holds locks too long). Pure join targets — no eager-load on `CharacterResource`. See *Character achievements endpoint* below.
- **PvE (Plan A redesign)** — Four tables: `game_data_raid_instances` (PK = journal-instance id, FK to expansions); `game_data_raid_encounters` (PK = journal-encounter id, FK cascade; `creature_display_id`, `portrait_url`); `game_data_mythic_keystone_dungeons` (`journal_instance_id` is **soft join key, not FK** — older-expansion dungeons may reference an instance we didn't sync); `game_data_keystone_affixes`. Synced by `blizzard:sync-game-data pve`, weekly. Per-instance `DB::transaction` (one per raid wrapping its encounters) — single mega-transaction across ~30 raids × ~8 encounters × 2-3 calls would blow rate budget. 404/error on any single instance/encounter/dungeon is logged and skipped. Dungeon endpoints live in **`dynamic-{region}`** (unlike the journal-instance family in `static-`). Dungeon `media_url` comes from **raider.io** — Blizzard has no media endpoint for `/mythic-keystone/dungeon/{id}`. Backfill: `php artisan dungeons:backfill-icons-from-raiderio --expansion=11`. Idempotent.

### Public endpoints

- `GET /api/v1/game-data/raid-instances?expansion=current|all` — eager-loads `encounters`. `current` resolves to `display_order=1`.
- `GET /api/v1/game-data/mythic-keystone-dungeons?season=current` — returns dungeons + the season's affixes piggybacked (~12-16 affix rows).

Both: no auth, no `data` envelope, `Cache-Control: max-age=3600, public`. FE caches with TanStack Query `staleTime: Infinity`. Empty table → `instances: []` / `dungeons: []`, not error.

- `GET /api/v1/characters/{region}/{realm}/{name}/achievements` (`CharacterAchievementsController::index`) — cursor-paginated joined rows: `{achievement_id, completed_timestamp, name, category_name}` + `meta.{total, per_page, next_cursor}`. Default per_page=100, max=200. Order: `completed_timestamp DESC NULLS LAST`, tiebreaker `achievement_id DESC`; cursor is base64url JSON `{ts, id}` so NULL-timestamp rows paginate through their own tail. Default filters `Feats of Strength`; `?include_feats=1` re-includes. Character payload no longer carries achievements (`CharacterController` doesn't eager-load, `CharacterResource` doesn't emit). Missing-row fallback: join returns `name=null`/`category_name=null`, FE renders `Achievement {id}`.

### Sync orchestration

- **Endgame-only Full syncs (2026-07-07).** Sub-endgame characters (`Character::isEndgame()` = `level >= blizzard.endgame_level`, default 90) only ever get Shallow/Standard on **every** lane: user lookup caps at Standard and skips slice-staleness checks, roster fan-out filters members to `level >= endgame_level` (sub-max members stay `guild_members` rows only), proactive tiers add the same level gate, `SyncUserCharacters` picks Full vs Standard per Battle.net character level. Defense-in-depth: the slice fan-out inside `SyncCharacterData::handle()` itself requires `$character->isEndgame()` against the freshly-fetched level, whatever lane dispatched Full. The Shallow→Full promotion now fires from any sub-Full depth (`depth !== Full && level >= endgame && mythics_synced_at === null`), so a tracked character that dings max escalates on its next Standard sync. One-off cleanup for pre-gating slice data: `php artisan characters:prune-submax-slices --dry-run`.
- **ProactiveSyncCharacters tier 1 dispatches Full.** Tier 1 (popular, daily 05:00) refreshes all slices. Tier 2 (long-tail, weekly Sun 06:00) stays Standard. Both tiers require `last_login_at` null-or-≤30d and `level >= endgame_level`. On-demand backfill: `php artisan blizzard:backfill-slices --limit=N` dispatches Full for endgame retail characters with any null `*_synced_at`.
- **Recursive teammate crawl.** On `SyncDepth::Full`, `dispatchTeammateCrawl()` runs as the last statement in `handle()` and dispatches one `Full` `SyncCharacterData` per Mythic+ teammate found in the seed's persisted `dungeon_run_members` for the current season — onto `blizzard-background`, with `crawlDepth = $this->crawlDepth + 1`. Gated on `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` (default `false`); depth ceiling `BLIZZARD_CRAWL_MAX_DEPTH` (default 1, hard-clamped to 2); per-seed dispatch cap `BLIZZARD_CRAWL_MAX_TEAMMATES_PER_SEED` (default 10 — uncapped fan-out regrew a 34k-job backlog in an hour, 2026-07-06). Skips teammates whose `Character.updated_at` is fresher than `BLIZZARD_CRAWL_RECENT_THRESHOLD` (default 604800 = 7d). Same rate-limit + health-check middleware as user-initiated. `ShouldBeUnique` key = `region:realm:name:depth` — `crawlDepth` is intentionally excluded so a seed and a crawl targeting the same character within 60s share the API call.
- **Auto-discover guild.** When `SyncCharacterData` writes a `Guild::firstOrCreate` shell, it dispatches `SyncGuildData` if `wasRecentlyCreated` — guild profile + roster populate ahead of the user's first click. `ShouldBeUnique` dedupes bursts.

RaiderIO seeder (`app/Services/RaiderIO/`) — lean discovery layer bootstrapping from raider.io top-lists. `php artisan raiderio:seed`. Detail: `docs/raiderio-seeder.md`.

`SyncDepth` enum: **Shallow** (profile only, roster members) | **Standard** (+ media/equipment/specs) | **Full** (+ 9 slices).

### Auth

Sanctum bearer tokens (issued on register/login, deleted on logout). Blizzard OAuth links a Battle.net account by exchanging an auth code, then dispatches `SyncUserCharacters`. Authorization via policies (`CharacterPolicy` checks `user_id`).

### Queue priority (Horizon)

1. `blizzard-auth` — token refresh
2. `blizzard-user-sync` — user-initiated lookups
3. `blizzard-roster-sync` — guild roster fan-out
4. `blizzard-background` — proactive sync

`SyncCharacterData` routing is declared via the `SyncOrigin` enum (`app/Enums/SyncOrigin.php`) — UserLookup → user-sync, RosterFanout → roster-sync, TeammateCrawl/Proactive → background. Never infer lanes from `crawlDepth`/`depth`. Roster fan-out is staggered at `blizzard.roster_fanout.jobs_per_minute` (default 30) so a cold guild can't flood a lane or churn jobs into `retryUntil`.

### Staleness model

`Character` and `Guild` have `isStale()` / `isRosterStale()` against `config/blizzard.php` thresholds. `Character` adds per-slice helpers (`isMythicsStale`, `isPvpStale`, ..., `isAchievementsStale`). For **endgame** characters, `CharacterService::getByIdentity()` dispatches `Full` when **any** slice is stale (or `$forceRefresh`), `Standard` only when profile alone is stale. **Sub-endgame** characters cap at Standard (even on `$forceRefresh`) and skip the slice checks entirely — their `*_synced_at` are null by design. `Character::freshness()` returns only the `profile` key for sub-max, so `isNeverSynced()` can't wedge them in a permanent `syncing`/`poll_after` loop; `CharacterResource` meta carries `profile_tier: 'full'|'basic'` and the controller skips slice eager-loads for basic tier (FE hides slice tabs off it). `?refresh=1` wiring + nonced `uniqueId` bypass of `ShouldBeUnique` is Plan 3 — see `TODO(Plan 3)` in `CharacterService`.

## Conventions

- Controllers thin — validation in FormRequests, logic in Services, transformation in Resources.
- Services return models or null; controllers decide HTTP response.
- All Blizzard jobs share: `readonly` ctor params, `onQueue()`, `middleware()` returning rate limiter + health check, typed `handle()` with DI, `failed()` for logging.
- Resources use `$this->whenLoaded('relation')` (except for the `belongsTo`-loaded-but-null trap above — use `relationLoaded` + null check).
- Common scopes: `byIdentity($name, $realm, $region)`, `recentlySearched($limit)`, `mostPopular($limit)`.
- Routes versioned under `/api/v1/` in `routes/api.php`.
- Config in `config/blizzard.php`, env-backed for thresholds, timeouts, rate limits.
