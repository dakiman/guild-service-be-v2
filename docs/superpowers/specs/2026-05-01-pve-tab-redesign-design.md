# PvE Tab Redesign — Design

- **Status:** APPROVED — decisions locked 2026-05-01. Ready for per-slice plans.
- **Date:** 2026-05-01
- **Branch target:** `feature/pve-tab-redesign` (off `master`)
- **Scope:** redesign the character "Dungeons & Raids" tab (currently `pages/character/CharacterPveTab.vue` + Raids/Mythic+ subtabs) to a single scrolling page modeled on raider.io, plus a new BE game-data resolver that supplies raid/dungeon/affix media and boss-roster denominators.

## 1. Context

The current PvE tab is a thin shell over two subtabs:

- `pages/character/CharacterPveTab.vue` renders a `CharacterTabStrip` with **Raids** and **Mythic+** subtabs.
- `pages/character/pve/RaidsSubtab.vue` → `components/character/RaidEncountersList.vue` — groups `character.raid_progress` by `instance_id`, renders one row per `(boss, difficulty)` pair with a colored left border for the difficulty.
- `pages/character/pve/MythicSubtab.vue` → `components/character/DungeonRunsList.vue` — flat list of `character.dungeon_runs[]`, each rendered as a card with keystone level, on-time/over-time badge, affix chips, and a member table.

Both views render only what's been killed or run. There is no headline progression number ("X/Y H"), no concept of bosses the character hasn't killed, no per-dungeon best-run aggregation, no per-spec score breakdown, and no media (boss portraits, raid backgrounds, dungeon icons, affix icons). The user has chosen raider.io as the reference layout (visual screenshot saved at `raiderio-melaniya-full.png` in repo root).

The redesign also closes a long-standing pattern gap: every other Plan 4/5 slice resolves game-data on the BE and joins it into `CharacterResource`, but the PvE views still inline raw Blizzard ids. Adding a "PvE game-data resolver" is the natural sibling to Plan 5 (factions/titles/mounts/achievements).

## 2. Decisions

All decisions resolved 2026-05-01 via brainstorming dialogue.

### 2.1 Page structure: subtabs vs single scrolling page

raider.io stacks "Raid Progression" and "Mythic+ Progression" as two sections on one scrolling page. Our existing setup uses two subtabs (Raids / Mythic+).

**DECISION:** collapse the subtabs. PvE becomes one continuous scrolling page with Raid Progression on top and Mythic+ Progression below. Routes `character-pve-raids` and `character-pve-mythic` are removed; `character-pve` becomes the leaf route. The `CharacterTabStrip` inside `CharacterPveTab.vue` is dropped.

Rationale: the subtab indirection was a holdover from the scaffold phase. With both sections visible, a viewer gets the full PvE story in one scroll without click-juggling, and the page reads as a single coherent armory view rather than two micro-pages.

### 2.2 BE scope: how aggressive about missing data

Three of raider.io's marquee elements need data we don't have today:

- **Total-boss-count denominator per raid difficulty** — without it we can't show the headline "6/9 H" number, only raw kill counts. The denominator comes from `/data/wow/journal-instance/{id}` (boss roster per raid).
- **Boss/dungeon/raid/affix media** — Blizzard's `/data/wow/media/...` endpoints expose portraits and icons.
- **iLvl-at-kill, first-kill date, leaderboard ranks** — significant additional work or impossible (raider.io's own scraped/computed data).

**DECISION:** ship denominators **and** all available media (boss portraits, raid backgrounds, dungeon icons, affix icons). iLvl-at-kill / first-kill / ranks are out of scope. Per the user: "our end goal is to have media and images for most of the content" — this slice establishes the BE pattern that future slices will follow for any game-data with associated media.

### 2.3 Tier / season scoping

raider.io defaults to "current tier" and offers a dropdown to older tiers, grouping multiple raids per tier (e.g. "Tier 35 (Voidsalm, Dreamrift, March on Quel'Danas)").

**DECISION:** show only the latest expansion's raids on the main view; a single "Show legacy raids" link expands a collapsed accordion of older expansions below. No tier abstraction — instances render directly under their expansion. For Mythic+, show only the current season; older seasons are not surfaced in this slice (defer to a future "season selector" if asked).

Rationale: simplest possible scope decision. Avoids needing a tier game-data table (raider.io's "Tier 35" is a raider.io-internal concept; Blizzard's API does not expose it). "Latest expansion" is derivable from the existing `game_data_expansions` table (Plan 5) sorted by `display_order desc`.

### 2.4 Mythic+ section layout

raider.io splits the M+ block across three views: per-dungeon best-run table, all-runs list, and leaderboard ranks (we have no rank data).

**DECISION:** two views, switched via tabs:
- **Best per Dungeon** (default) — one row per dungeon, showing the character's best run for that dungeon: level / score / time / affixes.
- **All Runs** — chronological list of every run in the season.

Both views are fully derivable client-side from `dungeon_runs[]`. No BE work is needed for either; the BE only contributes the `mythic_keystone_dungeons` game-data table (names + media) and the `keystone_affixes` table (icons).

### 2.5 New BE game-data tables

Three new `game_data_*` tables, populated by an Artisan sync command following the Plan 5 bulk-preload pattern (§2.2 of Plan 5 design):

```
game_data_raid_instances
  id              PK      ← Blizzard journal-instance id
  name
  expansion_id    FK → game_data_expansions
  display_order   int     ← Blizzard's display_order, for stable ordering within expansion
  media_url       text    ← raid background image

game_data_raid_encounters
  id              PK      ← Blizzard journal-encounter id
  raid_instance_id FK → game_data_raid_instances
  name
  display_order   int     ← boss order within the raid
  creature_display_id  int  nullable
  portrait_url    text    nullable ← boss portrait media

game_data_mythic_keystone_dungeons
  id              PK      ← Blizzard mythic-keystone dungeon id
  name
  media_url       text    nullable ← dungeon icon
  journal_instance_id  int  nullable ← join key to game_data_raid_instances if needed for fallback portrait

game_data_keystone_affixes
  id              PK      ← Blizzard keystone-affix id
  name
  icon_url        text
```

`game_data_expansions` already exists from Plan 5 — reused as-is.

Sync command — `php artisan blizzard:sync-game-data pve`:

1. `GET /data/wow/journal-instance/index` → list of all raid instances. For each: `GET /data/wow/journal-instance/{id}` (gives encounter list, expansion link), `GET /data/wow/media/journal-instance/{id}` (raid bg URL). Upsert into `game_data_raid_instances`.
2. For each encounter id from step 1: `GET /data/wow/journal-encounter/{id}` (creature display id), `GET /data/wow/media/creature-display/{display_id}` (portrait URL). Upsert into `game_data_raid_encounters`.
3. `GET /data/wow/mythic-keystone/dungeon/index` → for each: `GET /data/wow/mythic-keystone/dungeon/{id}` (links to journal_instance + media). Upsert into `game_data_mythic_keystone_dungeons`.
4. `GET /data/wow/keystone-affix/index` → for each: `GET /data/wow/keystone-affix/{id}` (name + media). Upsert into `game_data_keystone_affixes`.

Idempotent. Re-runnable. Scheduled weekly on the same hook as Plan 5.

### 2.6 API delivery: eager-load vs separate endpoint

Following the Plan 5 §2.3 hybrid decision:

- Raid instances + encounters + dungeons + affixes are **global static-ish data** (changes only on patch). Re-shipping them on every `CharacterResource` would bloat the payload by ~20-50KB of repeated names + media URLs, which is wasteful when the same data serves every character.
- The character payload remains lean. The PvE page fetches game-data once via two new endpoints and TanStack-caches them with `staleTime: Infinity`.

**DECISION:** two new GET endpoints:

- `GET /api/game-data/raid-instances?expansion=current` — returns instances scoped to the latest expansion (or all if `expansion=all`), each with embedded encounter roster and media URLs. Default `current`.
- `GET /api/game-data/mythic-keystone-dungeons?season=current` — returns dungeons in the current season (resolved by querying Blizzard's `/data/wow/mythic-keystone/season/index` for `current_season.id`, then `/data/wow/mythic-keystone/season/{id}` for the `periods`+`dungeons` list; cached server-side daily), each with media URL.
- Affixes ride along on the dungeons response (small, only ~12 rows total) keyed by id, so the FE doesn't need a third call.

Both endpoints are public (no auth) and return long-lived cacheable JSON. `Cache-Control: public, max-age=3600` plus FE-side `staleTime: Infinity`.

### 2.7 FE component breakdown

**Removed / deprecated:**
- `pages/character/pve/RaidsSubtab.vue` — deleted
- `pages/character/pve/MythicSubtab.vue` — deleted
- `components/character/RaidEncountersList.vue` — deleted (replaced)
- `components/character/DungeonRunsList.vue` — deleted (replaced)
- Routes `character-pve-raids` and `character-pve-mythic` removed from `router/index.ts`

**Modified:**
- `pages/character/CharacterPveTab.vue` — drops the `<CharacterTabStrip>` and `<router-view>`; renders the new layout directly.

**New:**
- `components/character/pve/PveHeadlineStrip.vue` — top headline `.ma-card` with two big stats (Best M+ Score · Raid Progression aggregate) and a season/expansion subtitle.
- `components/character/pve/RaidProgressionSection.vue` — section wrapper with header, "Show legacy raids" link, and a list of `RaidInstanceCard`s.
- `components/character/pve/RaidInstanceCard.vue` — one raid instance: title strip with raid background, difficulty tabs (LFR/Normal/Heroic/Mythic) showing per-difficulty `X/Y` counts, and a boss grid below.
- `components/character/pve/BossRow.vue` — single boss row with portrait, kill count, last-kill date. Ghosted state when not killed on the active difficulty.
- `components/character/pve/MythicPlusSection.vue` — section wrapper with header, KPI tiles, view tabs, and the active view component.
- `components/character/pve/MythicPlusKpiTiles.vue` — 4 `.ma-stat-pill` tiles: M+ Score, Timed 10+, Timed 5+, Timed 2+. Counts derived from `dungeon_runs[]` filtered to current season + `is_completed_on_time`.
- `components/character/pve/MythicPlusBestPerDungeon.vue` — per-dungeon best-run table.
- `components/character/pve/MythicPlusAllRuns.vue` — chronological run list (re-uses the existing per-run card layout with party member table, but updated to the `.ma-card` design language).
- `components/character/pve/AffixIcon.vue` — small icon component reading from the affix game-data dataset; falls back to a text chip while the image loads or if the icon URL is missing.

**New composables / API:**
- `composables/usePveGameData.ts` — exposes `useRaidInstances()` and `useMythicDungeons()` TanStack queries. Both have `staleTime: Infinity` and `gcTime: 24h`.
- `api/gameData.ts` — adds `getRaidInstances({ expansion })` and `getMythicKeystoneDungeons({ season })`.

**New types** (in `types/gameData.ts`, new file):
- `RaidInstanceGameData`, `RaidEncounterGameData`, `MythicKeystoneDungeonGameData`, `KeystoneAffixGameData`.

### 2.8 Visual / styling decisions

- Use existing design primitives — `.ma-card`, `.ma-stat-pill`, `.ma-text-heading` — per project memory and `frontend/src/style.css`. No new global tokens needed.
- Difficulty color convention reused as-is from `RaidEncountersList.vue`: `border-orange-500` (mythic), `border-purple-500` (heroic), `border-blue-500` (normal), `border-teal-500` (LFR).
- Raid background images render at low opacity (~20%) tinted into the `.ma-card` header strip. Use `bg-cover bg-center` with a dark overlay; falls back to a flat `bg-base-200` if `media_url` is null.
- Boss portraits render as 40px square thumbnails on the left of each `BossRow`. Fallback: a generic boss-silhouette SVG placeholder (existing `feedback/EmptyTab.vue` pattern, smaller).
- Ghosted (un-killed) boss rows: `opacity-40` on the row, no kill metadata column, label only.
- Affix icons: 24px square. `AffixIcon.vue` renders an `<img>` with the existing affix tooltip pattern (tooltip text = affix name).
- Mobile: KPI tiles wrap to 2x2 grid; difficulty tabs become a horizontal scroller; Best per Dungeon table collapses Score+Time into a stacked secondary line.

### 2.9 Out of scope

Explicitly **not** in this slice:
- Per-boss first-kill date, iLvl-at-kill, week-when-killed labels (e.g. "1st Week"). Requires Blizzard `/profile/wow/character/.../encounters` history that we don't currently fetch.
- Leaderboard ranks (raider.io's own scraped data).
- Per-dungeon score breakdown (Blizzard exposes only an aggregate `mythic_plus_rating` per character and per spec; no per-dungeon split is in the BE today).
- Tyrannical/Fortified split per dungeon-best (current season has only one weekly affix, so no split applies — would re-evaluate in a future expansion if Blizzard reintroduces dual weekly affixes).
- Season selector for legacy seasons.
- Tier abstraction (raider.io-internal concept).
- Backfilling existing characters with new game-data joins (the FE joins client-side at render time — no BE migration needed for character rows).

## 3. Slicing

Two plans will be written from this spec:

1. **Plan A — BE PvE game-data resolver.** Adds the four `game_data_*` tables, the sync command, the two new endpoints. Pure BE, no FE changes. Independently shippable: when complete, the endpoints return real data and a `curl` exercise proves it. FE continues rendering the old PvE tab unchanged until Plan B lands.

2. **Plan B — FE PvE tab rebuild.** Consumes the endpoints from Plan A. Replaces all existing PvE FE components with the new component tree, removes the subtab routes, ships the redesign.

Plan A must merge before Plan B starts.

## 4. Acceptance

- Visiting `/characters/eu/the-maelstrom/melaniya/pve` renders the new single-page layout: headline strip, Raid Progression section with the latest expansion's raids and proper "X/Y" headlines, Mythic+ Progression section with KPI tiles and the Best-per-Dungeon table by default.
- Boss portraits, raid backgrounds, dungeon icons, and affix icons all load (verified by inspecting network tab — no broken images on the test character).
- "Show legacy raids" expands older expansions below the current.
- "All Runs" tab in M+ shows a chronological list with party members.
- BE: `php artisan blizzard:sync-game-data pve` succeeds end-to-end on a fresh DB; re-running is a no-op (idempotent upserts).
- BE: `GET /api/game-data/raid-instances?expansion=current` and `GET /api/game-data/mythic-keystone-dungeons?season=current` return populated, schema-correct JSON.
- FE: `npm run build` and `vue-tsc -b` are green; no broken imports from removed components.
- BE: `php artisan test` is green for the new sync command + endpoints.
