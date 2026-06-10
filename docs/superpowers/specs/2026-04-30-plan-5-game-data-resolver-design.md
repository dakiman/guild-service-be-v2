# Game-Data Resolver — Design (informal "Plan 5")

- **Status:** APPROVED — decisions locked 2026-04-30. Ready for per-slice plans.
- **Date:** 2026-04-30
- **Branch target:** `feature/plan-5-game-data-resolver` (off `master`, after Plan 4 merges land)
- **Scope:** static reference-data resolver for factions, titles, mounts, achievements — closes Plan 4's deferred game-data follow-ups

## 1. Context

Plan 4 shipped on 2026-04-30 — five per-character slices (stats, titles, reputations, collections, achievements) merged to master across both repos. Each slice persists what Blizzard's character-profile endpoints return verbatim, but several rendering concerns were explicitly deferred because they require **game-data lookups** (`/data/wow/<resource>/{id}` rather than `/profile/wow/character/...`):

- `frontend/src/components/character/ReputationsList.vue:53-68` inlines an 11-entry `EXPANSION_BY_FACTION_ID` constant with a `TODO: lift to shared wow.ts constants once collections / achievements slices land`. Factions outside the hardcoded set fall back to a `Legacy` bucket.
- `frontend/src/components/character/AchievementsList.vue:42` renders raw IDs as `Achievement {{ achievement_id }}`. Wowhead tooltips fill in detail at hover time, but the in-list label is unresolved. Category grouping and Feats-of-Strength filtering are absent.
- `frontend/src/components/character/MountsSubtab.vue:18` renders `m.name` only. No source text, summon-spell link, or item-link enrichment.
- `frontend/src/pages/character/CharacterTitlesTab.vue` renders the gender-neutral `display_string` Blizzard returns on the character `/titles` endpoint. Gender-specific variants (`{name}, Lord of the Bears` vs `{name}, Lady of the Bears`) live on `/data/wow/title/{id}` and are not consulted.

Plan 4's per-slice CLAUDE.md notes call out each of these as "follow-up" or "out of this slice's scope". This document is that follow-up — informally "Plan 5" — and consolidates them into a single cross-cutting effort.

Paragon counts and renown levels were also deferred per the Plan 4 spec §2.2. They are **explicitly dropped from Plan 5** as outdated features (§2.4).

## 2. Decisions

All eight decisions resolved 2026-04-30 via brainstorming dialogue; rationale captured below for posterity.

### 2.1 Storage shape: DB tables vs cache-only vs hybrid

The deferred resources are static (~few hundred to ~40k rows each), patch-pinned, and identical for every character. Three options:

- **Normalized DB tables** — `game_data_factions`, `game_data_titles`, `game_data_mounts`, `game_data_achievements` etc. with columns. Migration. SQL joins on read.
- **Cache-only** (Laravel `Cache::remember`, 7-day TTL) — no schema, no migration. A Redis flush re-hammers Blizzard's rate limit on cold start.
- **Hybrid** — cache + DB backing.

**DECISION:** DB tables. Matches the Plan 4 pattern (DB-first reads, joins on `CharacterResource`, FE consumes via existing TanStack Query infrastructure). Gives free queryability for FE concerns like "all Feats-of-Strength achievements" or "expansion-grouped factions" without additional round-trips. The cache-cold-start blast radius against Blizzard's 100 req/s limit is the disqualifier for cache-only.

### 2.2 Refresh strategy: bulk preload vs lazy on-demand vs hybrid

`/data/wow/<resource>/index` returns `{id, name}` cheaply, but the per-resource fields we care about (achievement category, mount source/spell, title gender-variant) require per-ID detail calls. ~40k achievement details at 100 req/s ≈ 7 minutes; mounts/titles/factions are minutes-or-seconds tier.

- **Bulk preload via Artisan command** — `php artisan blizzard:sync-game-data {resource?}`, scheduled weekly. Tables always complete. New patch IDs render as fallback until the next scheduled run (or operator runs the command on patch day).
- **Lazy on-demand** — `SyncCharacterData` queues a per-ID resolve job whenever it sees an unknown resource. Tables grow with active player base.
- **Hybrid** — bulk + lazy fill.

**DECISION:** bulk preload. Patch-day gap is small (operator runs the command manually on patch day, or the weekly scheduler picks it up within 7 days). Hybrid's lazy fill adds a job class, error handling, and dedup logic for an edge case the operator can solve with a one-line invocation. Lazy-only precludes the global queries §2.1 cited as a benefit.

### 2.3 API delivery: eager-load vs separate endpoint vs hybrid

How the resolved data reaches the FE:

- **Eager-load everywhere on `CharacterResource`** — every per-character resource (titles, mounts, factions, achievements) ships with name/category/expansion already joined. Single round-trip per page. Achievements bloat the response by hundreds of KB of repeated category strings on max-level characters.
- **Eager-load small relations + separate endpoint for achievements** — titles/mounts/factions eager-load; achievements come from `/api/game-data/achievements` returning the full table once per session, HTTP-cached, FE joins client-side.
- **Pure separate endpoints** — every game-data lookup has its own endpoint. Most decoupled but every page now waits on N parallel game-data fetches.

**DECISION:** hybrid. Titles/mounts/factions are small enough per character (<100 each) that eager-load is cheap and keeps the FE simple. Achievements are the one outlier — a global cached endpoint handled once per session is genuinely nicer than re-shipping 5k category strings on every character reload.

### 2.4 Out of scope: paragon counts and renown levels

Plan 4 spec §2.2 deferred paragon counts (Shadowlands+) and renown levels (Dragonflight major factions) on the basis that they require additional per-faction endpoint calls.

**DECISION:** drop entirely. The user has flagged both as outdated features not worth the rate-limit cost. The base reputation table (faction id/name/standing/value) is sufficient for masked-armory parity at the rendering level. Reopen only if a concrete UX requirement emerges.

### 2.5 Schema scope per resource

Minimal-but-sufficient column sets:

```
game_data_factions
  id  PK              ← faction_id from Blizzard
  name
  parent_faction_id   nullable FK self
  expansion_id        FK → game_data_expansions

game_data_expansions  (small static reference, ~10 rows)
  id  PK
  name                e.g. "The War Within"
  display_order       int, newest first

game_data_titles
  id  PK              ← title_id
  name_male           e.g. "{name}, Lord of the Bears"
  name_female         e.g. "{name}, Lady of the Bears"

game_data_mounts
  id  PK              ← mount_id
  name
  description         nullable
  source_text         nullable, e.g. "Drop: Onyxia, Onyxia's Lair"
  summon_spell_id     nullable, for Wowhead spell tooltip
  item_id             nullable, item that teaches the mount

game_data_achievement_categories
  id  PK
  name
  parent_id           nullable FK self (Blizzard categories nest 1 level)
  display_order

game_data_achievements
  id  PK              ← achievement_id from Blizzard
  name
  description         nullable
  category_id         FK → game_data_achievement_categories
  points              int, achievement points
  is_account_wide     bool
```

**DECISION:** as above. Notable omissions:

- **No `is_feats_of_strength` column on `game_data_achievements`.** Blizzard's API does not tag FoS directly — they are identified by being children of the "Feats of Strength" *category*. `category_id` alone is sufficient; FE filters by category name.
- **`game_data_factions.expansion_id` populated from a BE-side seeder**, not Blizzard. Blizzard's `/data/wow/reputation-faction/{id}` endpoint does not expose expansion. The 11-entry mapping currently inlined in `ReputationsList.vue:54-68` moves to a Laravel seeder; operator extends per patch.

### 2.6 Rollout gating

Plan 4 slices each had a `BLIZZARD_SYNC_*_ENABLED` flag defaulting `false` so per-character writes could be ramped independently. Plan 5 is structurally different — it is global read-only enrichment, not per-character writes — so the "ramp before consumers see bad data" argument does not apply.

**DECISION:** no feature flag on Plan 5. Once a migration runs and the Artisan command populates the table, eager-loading on `CharacterResource` is unconditional. Missing join rows (new patch, command not yet rerun) emit `null` and the FE falls back to current ID-only rendering. No exception path.

### 2.7 Slicing structure

Plan 4 split into 5 ramp-gated slices each with its own plan doc, on a single long-lived feature branch fast-forward-merged at the end. Plan 5 follows the same model with **5 sub-slices**:

| # | Sub-slice | New tables | Bytes | FE consumer | Complexity |
|---|---|---|---|---|---|
| 1 | plan-5-factions | `game_data_factions`, `game_data_expansions` | ~few hundred rows | `ReputationsList.vue` | **S** |
| 2 | plan-5-titles | `game_data_titles` | ~1k rows | `CharacterTitlesTab.vue` | **S** |
| 3 | plan-5-mounts | `game_data_mounts` | ~1.2k rows | `MountsSubtab.vue` | **M** |
| 4 | plan-5-achievements | `game_data_achievements`, `game_data_achievement_categories` | ~40k rows + ~few hundred categories | `AchievementsList.vue` | **L** |
| 5 | plan-5-cleanup | — | — | — | **S** |

**Ordering rationale:** factions first because it is the smallest data, the biggest immediate UX win (drops the hardcoded `EXPANSION_BY_FACTION_ID` constant that users have already complained about), and establishes the `BlizzardGameDataClient` method pattern + Artisan command shape that later slices reuse. Titles and mounts follow as low-risk extensions of the established pattern. Achievements ships last because it is the largest dataset, introduces the nested categories table, and adds the only separate FE endpoint — best to harden the pattern first.

### 2.8 Plan 4 flag removal

The user has signaled intent to remove the five Plan 4 `BLIZZARD_SYNC_*_ENABLED` flags (`STATS`, `TITLES`, `REPUTATIONS`, `COLLECTIONS`, `ACHIEVEMENTS`) once Plan 4 ramp is verified in production. The flags do not map cleanly to Plan 5 sub-slices — `COLLECTIONS` covers mounts+pets+toys (only mounts is a Plan 5 concern), and `STATS` has no Plan 5 counterpart at all.

**DECISION:** flag removal lands in a trailing 5th sub-slice `plan-5-cleanup`. It removes all 5 flags + their `if(config(...))` guards in `SyncCharacterData::handle()` in one focused PR. The operator's green-light to merge this PR is the "Plan 4 is verified solid in prod" gate. Sub-slices 1-4 do not touch Plan 4 flags.

## 3. Architecture

### 3.1 Blizzard Game Data API integration

Six new methods on `app/Blizzard/Client/BlizzardGameDataClient.php`, all using **`namespace=static-{region}`** and `?locale=en_GB`, all wrapped in `Cache::remember(..., 7 days, ...)` matching the existing `getTalentTree` precedent (not the existing `getCurrentMythicPlusSeason` precedent — the season-index endpoint is dynamic). Method signatures:

```php
public function getFactionIndex(): array;
public function getFaction(int $id): array;

public function getTitleIndex(): array;
public function getTitle(int $id): array;

public function getMountIndex(): array;
public function getMount(int $id): array;

public function getAchievementCategoryIndex(): array;
public function getAchievementCategory(int $id): array;

public function getAchievementIndex(): array;
public function getAchievement(int $id): array;
```

**Note on namespace:** `CLAUDE.md` currently states "`BlizzardGameDataClient` sends `namespace=dynamic-{region}`" with mythic-season as the example. That generalization is wrong for static reference data. Plan 5 uses `static-{region}` for all six new methods (matching the in-tree `getTalentTree` precedent). The `plan-5-factions` slice should clarify the CLAUDE.md note while the change is fresh.

### 3.2 Mappers and DTOs

One mapper + one DTO per resource. Existing project convention is flat directories (no subfolders under `app/Blizzard/DTO/` or `app/Blizzard/Mappers/`); new files use the `GameData` prefix to disambiguate from per-character DTOs (`GameDataFaction.php` vs `CharacterReputation.php`). Mappers are the only place Blizzard's response shape is referenced; everything downstream consumes the typed DTOs.

DTO shapes mirror the table schemas in §2.5. Mappers handle:

- Field renaming (Blizzard returns `summon` `{ spell: { id } }` — flatten to `summon_spell_id`).
- Title gender extraction — Blizzard returns a `gender_name` object `{ male, female }` on `/data/wow/title/{id}`.
- Achievement category nesting — `parent_category_id` extracted from `category.parent_category.id` if present.
- Faction parent extraction — `parent_faction_id` from `category.id` if present (Blizzard nests sub-factions under a parent faction, not a category).

### 3.3 Sync command

One Artisan command `app/Console/Commands/SyncGameData.php` with signature:

```bash
php artisan blizzard:sync-game-data [resource]
```

Where `resource` is one of `factions`, `titles`, `mounts`, `achievements` (achievements implies categories), or omitted for "sync all". For each requested resource:

1. Call `getXxxIndex()` to get the list of IDs.
2. For each ID, call `getXxx($id)` and `Mapper::map(...)` to a DTO. Throttle naturally via the existing `BlizzardRateLimiter` middleware (80 req/s).
3. Inside one `DB::transaction`: upsert each DTO. **No delete-missing.** Blizzard's reference IDs are append-only across patches (an achievement/mount/title/faction is never removed once introduced), and a partial index response from a transient Blizzard issue must not silently wipe rows. Stale rows are tolerated; corrections come from the next successful upsert.
4. On 4xx for an individual ID (404 etc.): log warning, skip, continue. The job does not abort.

Scheduled via `app/Console/Kernel.php`:

```php
$schedule->command('blizzard:sync-game-data')->weekly();
```

### 3.4 Read-side wiring

**Factions, titles, mounts** eager-load on `CharacterResource`:

```php
// in CharacterController, before returning resource
$character->loadMissing([
  'titles.gameData',
  'mounts.gameData',
  'reputations.faction.expansion',
]);
```

Eloquent relations:

- `CharacterTitle::gameData()` → `game_data_titles` (FK: `title_id`); `TitleResource` exposes hydrated fields via `whenLoaded('gameData')`.
- `CharacterMount::gameData()` → `game_data_mounts` (FK: `mount_id`); `MountResource` ditto.
- `CharacterReputation::faction()` → `game_data_factions` (FK: `faction_id`); `GameDataFaction::expansion()` → `game_data_expansions` (FK: `expansion_id`); `ReputationResource` exposes `faction` via `whenLoaded('faction')`.

Reputations use a semantic name (`faction`) instead of `gameData` because the join target is itself a domain entity that the FE references directly (`reputation.faction.expansion.name`). Titles and mounts collapse to a single hop and use the generic `gameData` relation name.

**Achievements** does not eager-load. A new endpoint:

```
GET /api/v1/game-data/achievements
```

returns the entire `game_data_achievements` table joined to `game_data_achievement_categories` (~40k rows, ~1MB JSON, gzipped well under that). Response carries:

```http
Cache-Control: public, max-age=86400
ETag: "<hash-of-latest-row-mtime>"
```

FE consumes via a new `useGameDataAchievements()` TanStack Query hook with `staleTime: 24h`. `AchievementsList.vue` does the client-side join: filter `gameDataAchievements` by `character_achievements[].achievement_id`.

### 3.5 Frontend wiring

Per slice:

- **plan-5-factions:** drop `EXPANSION_BY_FACTION_ID` from `ReputationsList.vue:54-68`. Read `reputation.faction.expansion.name` and `reputation.faction.expansion.display_order` directly. Fallback for null expansion (new faction not yet seeded): bucket as `Legacy` with `display_order: 99`, matching today's behavior.
- **plan-5-titles:** `CharacterTitlesTab.vue` picks `name_male` vs `name_female` based on `character.gender`. Fallback to `display_string` if `gameData` is null.
- **plan-5-mounts:** `MountsSubtab.vue` renders `source_text` as a subtitle when present; uses `summon_spell_id` for a Wowhead `spell=` link when present (overrides current behavior of just rendering name).
- **plan-5-achievements:** `AchievementsList.vue` resolves names client-side via the TanStack hook. Adds optional category grouping (collapsed by default; FE-side state). Filters to drop Feats-of-Strength out of the main list (or move them to their own section) using the category-name match.

## 4. Cross-cutting concerns

- **Locale.** Single locale, hardcoded `?locale=en_GB` on every Game Data API call (matching the existing `BlizzardGameDataClient::getTalentTree` precedent). Project is single-locale today; multi-locale is a separate effort.
- **Rate limits.** All Game Data calls go through the same `BlizzardRateLimiter` middleware as profile endpoints (80 req/s/region). The 7-day `Cache::remember` wrapping on each client method means subsequent runs of `sync-game-data` mostly hit cache, not Blizzard.
- **Missing-row behavior.** If `character_titles` references a `title_id` that has no `game_data_titles` row (new patch, command not yet rerun), the eager-loaded relation is null, the resource emits null, and the FE falls back to today's rendering. No 500, no cache invalidation needed.
- **Migration ordering.** Each sub-slice's migration creates only the tables that slice owns. `plan-5-factions` creates `game_data_expansions` first (FK target) then `game_data_factions`. `plan-5-achievements` creates `game_data_achievement_categories` first then `game_data_achievements`. No retroactive FK additions.
- **Initial deploy.** First time each migration lands in prod, the table is empty. Deploy runbook for each sub-slice ends with `php artisan blizzard:sync-game-data <resource>` to populate before the FE goes live. Documented in each slice's plan doc.
- **Test strategy.**
  - Client method unit tests: mocked HTTP, assert URL + namespace + locale + cache key.
  - Mapper tests: fixture-based DTO assertions.
  - Artisan command integration test: mocked `BlizzardGameDataClient`, assert upserts + idempotency on rerun.
  - `CharacterResource` snapshot tests: extended for each enriched resource.
  - `/api/v1/game-data/achievements` endpoint test: response shape + Cache-Control header.
  - Plan 4 cadence: BE `composer test` green at each slice boundary; FE `npm run build` green at each slice boundary.

## 5. Out of scope

- Paragon counts (Shadowlands+) and renown levels (Dragonflight major factions) — explicitly dropped per §2.4.
- Pet and toy game-data enrichment beyond what character-side persistence already provides. `character_pets.creature_display_id` and `character_toys.toy_id` are already sufficient for Wowhead linking; richer data (breed, source) is a future effort.
- Achievement criteria progress (the per-achievement step list). Out of scope for the current "list view" UX; adding it requires a much larger payload per achievement.
- Multi-locale support. en_GB only.
- Stats slice cleanup beyond removing the `BLIZZARD_SYNC_STATS_ENABLED` flag in `plan-5-cleanup`. Stats are already structured Blizzard data with no game-data lookups needed.

## 6. Implementation handoff

This is a **spec**, not a plan. Per project convention (see `docs/superpowers/plans/2026-04-28-character-{stats,titles,reputations,collections,achievements}-slice.md`), each sub-slice gets its own plan doc with:

- Concrete file lists per task
- Failing-test-first workflows
- Per-task commit messages
- Verification commands

Five plan docs in this case:

- `2026-04-30-plan-5-factions-slice.md`
- `2026-04-30-plan-5-titles-slice.md`
- `2026-04-30-plan-5-mounts-slice.md`
- `2026-04-30-plan-5-achievements-slice.md`
- `2026-04-30-plan-5-cleanup.md`

Branch: `feature/plan-5-game-data-resolver` cut from `master` after Plan 4 merges (already true as of 2026-04-30). Each sub-slice is a commit cluster on that branch. Final fast-forward merge to master once `plan-5-cleanup` is approved (which itself is gated on Plan 4 being verified in prod).

CLAUDE.md update: each sub-slice adds a bullet to the per-slice notes section — "Game-data factions slice", "Game-data titles slice", etc. — mirroring how Plan 4's slices documented themselves. The `plan-5-cleanup` slice removes the obsolete `BLIZZARD_SYNC_*_ENABLED` callouts in CLAUDE.md and updates the "Game-data endpoints require `dynamic-{region}`" line to distinguish dynamic (mythic-keystone seasons, leaderboards) from static (achievement, mount, title, faction, talent-tree).
