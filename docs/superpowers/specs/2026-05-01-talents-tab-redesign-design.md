# Talents Tab Redesign

**Status:** Design — not yet implemented.
**Date:** 2026-05-01
**Scope:** Backend (new game-data resolver) + Frontend (full rewrite of the talents tab).

## Context

The current `frontend/src/components/character/TalentTree.vue` is a three-column card that lists picked talents per tree as plain text fractions ("1/3", "2/2") linked to Wowhead. There's no tree visualization, no icons, no sense of which talents the player chose. This spec replaces that with a full positional talent tree (raider.io-style) plus a small "headline" summary strip above it.

Reference research: `tmp/raider-talents-review/` — screenshots of raider.io's collapsed and expanded talent cards for Melaniya (eu/the-maelstrom). Key observations:
- Raider.io shows a curated subset (~5–10 icons across 3 columns) by default, expanding inline to a full positional tree.
- Octagonal frames distinguish "choice" (pick-one-of-two) nodes from regular nodes.
- Picked nodes are lit, unpicked dimmed, with faint connector lines between tiers.
- Wowhead-style tooltips on hover.

## Goals

- Render the full talent tree per character (Class / Hero / Spec) — every node, picked + unpicked, in its true grid position with connector lines between tiers.
- Surface a 5–6-icon summary strip above the full tree as a build identity-at-a-glance.
- Keep `Copy loadout`; add a `Talent Calculator ↗` outbound link.
- PvP talents stay below the full tree as a small flex-wrap row.

## Non-goals

- Editing talents in-app — read-only.
- Talent change detection / build history.
- Building our own tooltip renderer (Wowhead `power.js` handles tooltips).
- Classic talent rendering — out of scope; classic chars already render a simpler placeholder, kept as-is.
- Embedding a talent calculator in-app.

## Backend

### New table `game_data_talent_trees`

Composite PK `(tree_id, spec_id)`. Stores tree topology as a JSONB blob.

```sql
CREATE TABLE game_data_talent_trees (
    tree_id    bigint      NOT NULL,
    spec_id    bigint      NOT NULL,
    name       text        NOT NULL,                  -- e.g. "Subtlety Talents"
    tree       jsonb       NOT NULL,                  -- topology, see shape below
    synced_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (tree_id, spec_id)
);
```

`tree` JSONB shape:
```json
{
  "class_nodes": [
    {
      "id": 12345,
      "display_row": 0,
      "display_col": 4,
      "type": "regular",
      "ranks": [
        { "spell_id": 67890, "name": "Shadow Dance" }
      ],
      "choice_options": null
    }
  ],
  "spec_nodes": [...],
  "hero_trees": [
    { "id": 31, "name": "Deathstalker", "nodes": [...] }
  ],
  "edges": [
    { "from": 12345, "to": 67890 }
  ]
}
```

`type` is either `"regular"` or `"choice"`. When `"choice"`, `choice_options` is `[{ talent_id, spell_id, name }, { ... }]` — the two alternatives, in Blizzard's order. `choice_options[0]` is rendered as the placeholder when the node is unpicked.

`edges` is a flat global list across class + spec + hero subtrees, built by walking each Blizzard node's `unlocks[]` field. `from`/`to` are node ids.

### Sync command

Extend `php artisan blizzard:sync-game-data` with a new `talent-trees` slice (matches the existing factions / titles / mounts / achievements / pve resolver pattern):

1. Fetch `/data/wow/playable-specialization/index` (`static-{region}` namespace) — gives every spec id and a reference to its talent tree.
2. For each `(tree_id, spec_id)` pair: call `BlizzardGameDataClient::getTalentTree($treeId, $specId)` (already exists — hits `/data/wow/talent-tree/{treeId}/playable-specialization/{specId}`, cached).
3. Map the response → flatten into the JSONB shape above using a new `TalentTreeMapper`.
4. Upsert into `game_data_talent_trees` inside a per-pair `DB::transaction`.
5. Per-pair failure tolerance: log + skip on 404 or thrown error; do not abort the rest of the sync.

Scheduled weekly with the rest of the game-data sync. Runs in the no-arg `blizzard:sync-game-data` sweep too.

### New endpoint

`GET /api/v1/game-data/talent-trees/{treeId}/{specId}` — public, no auth. Lives on the existing `GameDataController` alongside the PvE endpoints.

Response:
```json
{
  "tree_id": 795,
  "spec_id": 261,
  "name": "Subtlety Talents",
  "tree": { ... }
}
```

`Cache-Control: max-age=3600, public`. 404 when no row exists for the pair (treated as "not yet synced" — FE falls back gracefully).

### Character payload extension

The character record gains two new persisted columns:
- `active_specialization_id: int` (nullable)
- `talent_tree_id: int` (nullable)

Captured during `CharacterSpecializationMapper::map()` at sync time. Today the mapper already extracts both internally (`$activeSpecId` and `$treeId`); they're just not persisted. The `CharacterSpecialization` DTO gains two new constructor params, `Character` gains two columns, the resource emits them.

`CharacterResource` adds:
```json
"active_specialization_id": 261,
"talent_tree_id": 795
```

The existing `active_specialization` (display name string) and `talents` JSONB shape are unchanged. Since the project is not in production, no backfill migration is needed — add the columns nullable and the next sync populates them.

## Frontend

### Components

```
frontend/src/components/character/talents/
  TalentTree.vue              — root container; replaces the existing TalentTree.vue
  TalentSummaryStrip.vue      — the 5–6 icon headline
  TalentTreeColumn.vue        — one tree (Class / Hero / Spec); renders grid + edge overlay
  TalentNode.vue              — single node icon, frame, badge, picked/unpicked, Wowhead anchor
  TalentEdges.vue             — SVG overlay drawing connector lines
```

The existing `frontend/src/components/character/TalentTree.vue` is **deleted**; the new one lives under `talents/`. `pages/character/CharacterTalentsTab.vue` updates its import path.

### Composables

```
frontend/src/composables/
  useTalentTree.ts            — TanStack Query for the new endpoint
  useTalentSummary.ts         — pure fn applying the picking rule
```

`useTalentTree`: query key `['talent-tree', treeId, specId]`, `staleTime: Infinity`, `gcTime: 24h`. Same pattern as `usePveGameData` (changes only on patch).

`useTalentSummary`: pure synchronous function `(picked: CharacterTalents, tree: TalentTree) => TalentNodeRef[]` — no async state, no Vue reactivity inside. Computed downstream by the component using `computed()`.

### Picking rule (summary strip)

Given the picked talents and the full tree topology:

| Section | What | Quota |
|---|---|---|
| Class | Deepest 2 picked **choice** nodes by `display_row` desc | 2 |
| Hero  | **Active hero tree** entry/keystone talent (top picked node — lowest `display_row` with picks) + deepest 1 picked choice node within the same hero tree | 1–2 |
| Spec  | Deepest 2 picked **choice** nodes | 2 |

The **active hero tree** is the single entry in `tree.hero_trees[]` containing the character's picked hero-talent node ids — derived client-side by intersecting `picked.hero[].id` with each hero tree's `nodes[].id`. A retail character only ever has one active hero tree.

Steady state: **6 icons**, dropping to 5 if the active hero tree has no picked choice node.

**Fallback** — if a tree has fewer choice nodes picked than its quota, top up with the **deepest non-choice picked talents** until quota is met. Ensures the strip never looks broken.

**Tie-break** on equal `display_row`: lower `display_col` first, then lower node id.

**Order in the rendered strip:** Class → Hero → Spec, left to right.

### Layout

**Desktop / tablet (≥768px):**
- Summary strip (single row, 5–6 icons) at the top of the card.
- Three columns side by side below: `Class | Hero | Spec`. Hero column narrower (single-zigzag column matching the smaller hero-tree topology).
- The Hero column renders only the **active hero tree** (the one with picked nodes — see picking-rule section). The other hero trees stored in `tree.hero_trees[]` for the same spec are not rendered.

**Mobile (<768px):**
- Summary strip at top, scaled to fit one row.
- Three columns stack vertically: Class first, then Hero, then Spec. Each column's internal grid is unchanged (absolute-positioned by `display_row × display_col`); we just stack the section blocks.

No horizontal scroll, no zoom — a class tree at ~10 rows × 3 cols of 40px nodes is ~140×400px, which fits a phone column.

### Node rendering

- **Frame:** CSS-only, no images. Round (`rounded-full`) for `type=regular`; octagonal (`clip-path: polygon(...)`) for `type=choice`.
- **Picked vs unpicked:** picked = full opacity + `box-shadow: 0 0 8px <class-color>` (color from `frontend/src/utils/wowConstants.ts::CLASS_COLORS`); unpicked = `opacity: 0.35`, no ring. No greyscale filter (icons stay recognizable).
- **Unpicked choice node:** show `tree.choice_options[0].spell_id`'s icon, dimmed.
- **Rank badge:** `{rank}/{max_rank}` text in the bottom-right corner of the icon when `max_rank > 1`. Hidden on single-rank nodes.
- **Tooltips:** every node renders an `<a data-wowhead="spell={spell_id}">` (existing `WowheadLink.vue` pattern). Wowhead `power.js` handles tooltips for both picked and unpicked nodes. Call `useWowheadRefresh(deps)` after the tree resolves so newly-rendered anchors are hydrated.
  - Picked regular node: `spell_id` = `picked_talent.spell_id` (already carries the active rank's spell).
  - Picked choice node: `spell_id` = `picked_talent.spell_id` (the option the player chose).
  - Unpicked regular node: `spell_id` = `tree_node.ranks[0].spell_id` (rank-1 default).
  - Unpicked choice node: `spell_id` = `tree_node.choice_options[0].spell_id` (option A default — same icon as the visible frame).

### Edges

SVG overlay positioned absolutely over each `TalentTreeColumn`. For each edge whose `from` and `to` both belong to the column's tree, draw a thin line from the bottom-center of the `from` node to the top-center of the `to` node:
- Picked-to-picked edge: `stroke-width: 1px`, `stroke: rgb(255 255 255 / 0.4)`.
- Other edges: `stroke-width: 1px`, `stroke: rgb(255 255 255 / 0.15)`.

Coordinates derived from `display_row × display_col` × cell-size + half-cell. Cell size is a single CSS variable so desktop/mobile sizing is one knob.

### Loading & error states

- **Character loaded, tree still fetching:** summary strip renders 5 round-skeleton placeholders; full tree is one large skeleton block. Flips to real content on resolve (~100ms warm cache, ~500ms cold).
- **Tree fetch 404** (game-data not yet synced for this `(treeId, specId)` pair): fall back to the *current* picked-only flat-list rendering, with a small footer note "Full tree not available for this spec yet." Talent tab is never broken even if the BE sync misses a spec.
- **Character has no talents at all** (low-level): existing "No talents" empty state stays.
- **Classic character** (`character.game_version === 'classic'`): existing classic placeholder is rendered; the talent-tree composable short-circuits and does not fetch.

### Copy loadout & Talent Calculator

Both live in the top-right of the new `TalentTree.vue` header:
- `Copy loadout` — existing implementation, unchanged. Hidden when `loadout_code` is null.
- `Talent Calculator ↗` — `<a target="_blank" rel="noopener" :href="`https://www.wowhead.com/talent-calc/blizzard/${loadoutCode}`">`. Hidden when `loadout_code` is null.

### PvP talents

Stay below the full tree as a small flex-wrap row — same conceptual layout as today. Reuse `TalentNode.vue` (with `max_rank=1`, no rank badge). Not added to the summary strip — PvP talents are orthogonal to the build identity.

## Testing

### Backend

- `TalentTreeMapperTest` — fixture from a real `/data/wow/talent-tree/795/playable-specialization/261` response (Sub Rogue). Asserts node positions, type=choice flagged correctly on octagonal nodes, edges flattened from `unlocks[]`, hero trees split into named groups.
- `BlizzardGameDataSyncCommandTest::test_talent_trees_slice` — end-to-end sync against a recorded fixture; asserts row count and one full round-trip of the JSONB.
- `GameDataControllerTalentTreeTest` — asserts 200 response shape, 404 when missing, `Cache-Control` header.
- Extend `RetailCharacterEndpointTest` (data-provider tests) to include the two new fields (`active_specialization_id`, `talent_tree_id`) when `talents` is non-empty.

### Frontend

- `useTalentSummary.spec.ts` (Vitest) — picking-rule unit tests: full-quota case, hero with no picked choice node (5 icons), tie-break on equal `display_row`, sparse low-level character (fallback path), classic character (returns empty).
- Cypress smoke for `/characters/eu/the-maelstrom/melaniya/talents`: summary strip renders ≥5 icons; full tree shows 3 columns; at least one octagonal frame visible.

## Out of scope (future work)

- Talent change detection — hash the loadout_code on each sync, surface "build changed since last sync" badge.
- Per-spec saved-builds comparison ("M+ build" vs "raid build" / "Sub Rogue Trickster" vs "Sub Rogue Deathstalker").
- Embedded talent calculator (writer-mode component with point-allocation rules).
- Cross-character build diff.
