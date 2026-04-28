# Character Collections + Stats — Design (informal "Plan 4")

- **Status:** APPROVED — decisions locked 2026-04-28. Ready for per-slice plans.
- **Date:** 2026-04-28
- **Branch target:** `feature/character-collections-and-stats` (off `master`; see §2.4)
- **Scope:** five FE-pending character data domains the Path B empty-state tabs are waiting on

## 1. Context

After Path B (the masked-armory.com character-page redesign) shipped on 2026-04-28 with theme tokens (`f827fd0`), router rewire (`72ccd8e`), data-contract follow-ups (`74bfe5f`, `0f87a2e`, `62d5795`), and equipment-tooltip context plumbing (`62ea844`, `6ad0e3f`), the FE renders `EmptyTab` placeholders for five tabs whose data the BE has not yet exposed:

- `frontend/src/pages/character/CharacterSummaryTab.vue:8` renders `CharacterStatsCardPlaceholder` — the only **above-the-fold** placeholder.
- `frontend/src/pages/character/CharacterTitlesTab.vue`
- `frontend/src/pages/character/CharacterCollectionsTab.vue` + subtabs `mounts`, `pets`, `toys`
- `frontend/src/pages/character/CharacterReputationsTab.vue`
- `frontend/src/pages/character/CharacterAchievementsTab.vue`

CLAUDE.md's "Plan 2" (mythic+ rating, PvP brackets, professions, raid encounter kills — see `e4dbadf`) is fully shipped. Plan 3 in CLAUDE.md is Classic persistence (status TBD; verify before assuming). This document is a **successor scope** — informally called "Plan 4" — but the label is unblessed (see §2.4).

## 2. Decisions

All four decisions resolved 2026-04-28; rationale captured below for posterity.

### 2.1 Stats schema: JSONB column vs flat `character_stats` table

Blizzard's `/profile/wow/character/{realm}/{name}/character-stats` endpoint returns ~80 fields with class- and spec-conditional schemas (e.g. `mana_regen` only for casters; `melee_crit_chance` separate from `ranged_crit_chance`; weapon skill values appear only in Classic). Two options:

- **JSONB column** (`stats` JSONB + `stats_synced_at` on `characters`)
  - Pros: low schema cost, fits the existing per-slice pattern, smallest diff. FE always gets the full payload. No migration churn each Blizzard patch.
  - Cons: hard to query/index individual fields in SQL (`stats->>'strength'` works but no native indexing on dynamic keys). Type-safety lives in the FE, not the BE.

- **Flat table** (`character_stats` with one column per Blizzard field)
  - Pros: queryable, indexable, type-safe at the Eloquent layer. Future leaderboard-by-stat features come for free.
  - Cons: painful migrations every expansion. Schema bikeshedding (which fields? class-conditional nullables?).

**DECISION:** JSONB. Lowest line count, matches existing slice pattern, and the FE never queries by stat — it just renders the payload. If a future leaderboard-by-stat feature emerges, denormalize at that point.

### 2.2 Reputations scope

The 2026-04-22 spec (`docs/superpowers/specs/2026-04-22-v1-feature-parity-and-enrichment-design.md`) does not mention reputations. They are neither in nor out of v1 scope. Three sub-decisions:

- Persist faction id, name, standing, value? **Yes** (table stakes).
- Paragon counts (Shadowlands+ factions)? **DEFERRED** to a follow-up slice.
- Renown levels (Dragonflight major factions)? **DEFERRED** to a follow-up slice.

**DECISION:** ship faction id/name/standing/value in this slice. Paragon and renown require additional per-faction or per-id endpoint calls (rate-limit cost) and are not load-bearing for masked-armory parity at the basic-table level. Revisit once `CharacterReputationsTab.vue` renders the basic table and a concrete parity gap is identified.

### 2.3 "Statistics dropped after Q5" line in 2026-04-22 spec

The earlier spec (line 35) lists "Character titles, achievement summary, statistics (dropped after Q5)" as out-of-scope. Two readings:

- **(a)** "statistics" = Blizzard's `/character-stats` endpoint (the very thing we now want to expose). If so, this slice **reverses a prior decision** and §1 of this doc should call out the rationale.
- **(b)** "statistics" = the v1 UI's separate "Statistics" tab (a distinct concept from the modern Blizzard endpoint). If so, no reversal.

**DECISION:** interpretation (b). The line sits between "character titles" and "achievement summary", which are themselves v1-tab concepts (the v1 character page had separate Titles, Achievements, and Statistics tabs as discrete UI surfaces). "Statistics" in that context refers to the v1 UI tab — counts of /played, deaths, quests completed, etc. — not Blizzard's modern `/character-stats` endpoint (which surfaces item-level / primary stats / secondary ratings — the kind of data masked-armory features prominently). No reversal of prior intent: this slice ships the modern endpoint, not v1's Statistics tab. The v1 Statistics tab remains out of scope.

### 2.4 Branch name and Plan numbering

`feature/plan-2-retail-character-enrichment` already holds Plan 2 + Blizzard name normalization + 404-marker caching. Adding Plan 4 work to it would compound the misnamed branch. Options:

- New branch `feature/character-collections-and-stats` off `master` (after Plan 2 merges, if it hasn't yet).
- New branch `feature/plan-4-character-collections` if the team adopts the Plan-N labelling.
- Avoid the Plan 4 label entirely and use a thematic name like `feature/fe-pending-character-domains`.

**DECISION:** new branch `feature/character-collections-and-stats` off `master`. Branched when the first slice begins (timing: after `feature/plan-2-retail-character-enrichment` merges into `master`, or rebased onto whatever lands first). The "Plan 4" label is **dropped from branch naming** but kept as an informal in-doc reference. When the first slice ships, add a CLAUDE.md bullet under the existing per-slice section noting the new scope (collections + stats) — keep using "Plan N" labels inline (CLAUDE.md already references Plan 2 and Plan 3 inline) but the team need not bless "Plan 4" as a formal name.

## 3. Slices in scope

Recommended sequencing (by ROI):

| # | Slice | Endpoint(s) | Persistence | FE consumer | Complexity |
|---|---|---|---|---|---|
| 1 | Aggregate stats | `/character-stats` | JSONB on `characters` (per §2.1 rec) | `CharacterSummaryTab.vue` (above-the-fold) | **S** |
| 2 | Titles | `/titles` | `character_titles` + delete-missing | `CharacterTitlesTab.vue` | **M** |
| 3 | Reputations | `/reputations` | `character_reputations` + delete-missing | `CharacterReputationsTab.vue` | **M** |
| 4 | Collections | `/collections/mounts`, `/collections/pets`, `/collections/toys` | three sub-tables (or one polymorphic) + delete-missing | `CharacterCollectionsTab.vue` + subtabs | **L** (3 sub-slices) |
| 5 | Achievements | `/achievements` | `character_achievements` + index `(character_id, achievement_id)` | `CharacterAchievementsTab.vue` | **L** |

Each slice follows the **established Plan 2 pattern** (per `CLAUDE.md` line 67-72 and `app/Blizzard/Jobs/SyncCharacterData.php`):

1. Dedicated `BlizzardProfileClient` method.
2. DTO + Mapper.
3. `SyncCharacterData::handle()` slice block guarded by `BLIZZARD_SYNC_{SLICE}_ENABLED` flag.
4. `try { DB::transaction(fn() => upsert + delete-missing) } catch` — failures don't abort other slices.
5. On success, update `*_synced_at` column.
6. `CharacterService::is{Slice}Stale()` helper feeds the staleness OR-chain.
7. `CharacterResource` exposes via `whenLoaded` + `meta.freshness.{slice}` map.
8. `tests/Feature/Endpoints/RetailCharacterEndpointTest.php` extended with populated-data assertions.

## 4. Out of scope

- Achievement **category** rendering (the BE returns raw IDs; FE-side category/Feats-of-Strength lookup is a separate game-data effort).
- Mount/pet/toy game-data details (icon, source) — handled by Wowhead at render time.
- Heirlooms (separate Blizzard endpoint, low priority — defer).
- Renown / paragon (per §2.2) until a follow-up.
- Classic variants of these slices — Plan 3 (Classic persistence) owns those.

## 5. Cross-cutting concerns

- Each slice's `BLIZZARD_SYNC_{SLICE}_ENABLED` flag defaults to `false` so slices can be ramped independently in production.
- The `blizzard:backfill-slices` artisan command (added in `359c6c1`) needs to learn each new slice's `*_synced_at` null check.
- `CharacterResource` currently exposes `meta.freshness.{equipment, talents, mythic_plus_rating, pvp_brackets, professions, raid_encounter_kills}`. Each new slice adds a key here; the FE's `FreshnessChips` component (`f49cc7e`) automatically picks them up via `meta.freshness`.
- Blizzard rate limits: 100 req/sec/region per OAuth token. Achievements and collections are the heaviest endpoints; verify the existing throttling middleware covers them before ramping their flags to `true`.

## 6. Implementation handoff

This is a **spec**, not a plan. Per the team's convention (see `docs/superpowers/plans/2026-04-22-plan-1-schema-and-equipment-enrichment.md` and `2026-04-27-blizzard-name-normalization.md`), each implementation pass needs its own plan doc with:

- Concrete file lists per task
- Failing-test-first workflows
- Per-task commit messages
- Verification commands

**Recommended next step after §2 is resolved:**
1. Land §2 decisions inline in this file (replace TBDs with the chosen path; archive the "Open decisions" header).
2. Write `docs/superpowers/plans/2026-04-XX-character-stats-slice.md` for the first slice (aggregate stats).
3. Execute that plan via `superpowers:executing-plans` or sub-agent dispatch.
4. Repeat for remaining slices in the order above.

Slices 1-3 likely fit into a single PR each; slice 4 (collections) is naturally three sub-slices; slice 5 (achievements) probably needs to land behind its flag with a slow ramp due to row volume.
