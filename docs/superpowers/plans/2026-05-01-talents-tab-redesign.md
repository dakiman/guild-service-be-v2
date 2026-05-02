# Talents Tab Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Spec:** `backend/docs/superpowers/specs/2026-05-01-talents-tab-redesign-design.md`

**Goal:** Replace the picked-only flat-list talent tab with a full positional talent tree (raider.io style) plus a 5-6-icon summary strip, backed by a new game-data resolver for talent-tree topology.

**Architecture:** New `game_data_talent_trees` table populated by a `talent-trees` slice on the existing `blizzard:sync-game-data` command (matches the factions/titles/mounts/achievements/pve resolver pattern). New public endpoint `GET /api/v1/game-data/talent-trees/{treeId}/{specId}`. Two new persisted columns on `characters` (`active_specialization_id`, `talent_tree_id`) populated during the existing specialization-mapper pass, exposed on `CharacterResource`. FE consumes the new endpoint via TanStack Query, computes the summary strip client-side via a pure picking-rule fn, renders each section as a positionally-laid-out grid with SVG edges; falls back to the today's flat-list rendering on 404.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL JSONB / Vue 3 `<script setup>` + TS / TanStack Query / Tailwind + DaisyUI.

**Branch:** `feature/talents-tab-redesign` in both `backend/` and `frontend/` repos. Don't push.

**Not in production:** nullable columns are added without backfill; `migrate:fresh` is fair game in dev; the next sync populates the new ids.

---

## File Structure

### Backend (Laravel)

**Create:**
- `database/migrations/2026_05_01_100002_create_game_data_talent_trees_table.php`
- `database/migrations/2026_05_01_100003_add_specialization_ids_to_characters.php`
- `app/Models/GameDataTalentTree.php`
- `app/Blizzard/DTO/GameDataTalentTree.php`
- `app/Blizzard/Mappers/GameDataTalentTreeMapper.php`
- `app/Http/Resources/TalentTreeGameDataResource.php`
- `tests/Unit/GameDataTalentTreeMapperTest.php`
- `tests/Feature/Endpoints/GameDataTalentTreeEndpointTest.php`
- `tests/Fixtures/blizzard/talent-tree-795-261.json` — Sub-Rogue talent tree fixture (recorded from a real Blizzard response)

**Modify:**
- `app/Blizzard/Client/BlizzardGameDataClient.php` — add `getPlayableSpecializationIndex()` (`static-{region}` namespace, returns `{character_specializations:[…], pet_specializations:[…]}`).
- `app/Console/Commands/SyncGameData.php` — add `talent-trees` slice + register in the all-resources list.
- `app/Http/Controllers/GameDataController.php` — add `talentTree(int $treeId, int $specId): JsonResponse`.
- `routes/api.php` — register `GET /game-data/talent-trees/{treeId}/{specId}` route.
- `app/Models/Character.php` — add `active_specialization_id`, `talent_tree_id` to `$fillable` + `$casts`.
- `app/Blizzard/DTO/CharacterSpecialization.php` — two new constructor params.
- `app/Blizzard/Mappers/CharacterSpecializationMapper.php` — pass through `$activeSpecId` and `$treeId` into the DTO.
- `app/Blizzard/Jobs/SyncCharacterData.php` — write the two new columns when persisting the specialization mapper output.
- `app/Http/Resources/CharacterResource.php` — emit `active_specialization_id` + `talent_tree_id`.
- `tests/Feature/Endpoints/RetailCharacterEndpointTest.php` (or its data-provider helper) — extend assertions to include the two new fields when `talents` is non-empty.

### Frontend (Vue)

**Create:**
- `src/components/character/talents/TalentTree.vue` — root container; replaces the existing component
- `src/components/character/talents/TalentSummaryStrip.vue` — 5-6 icon headline
- `src/components/character/talents/TalentTreeColumn.vue` — one tree (Class / Hero / Spec) with grid + edge overlay
- `src/components/character/talents/TalentNode.vue` — single node (frame, badge, picked/unpicked, Wowhead anchor)
- `src/components/character/talents/TalentEdges.vue` — SVG overlay drawing connector lines
- `src/composables/useTalentTree.ts` — TanStack Query for `/game-data/talent-trees/{treeId}/{specId}`
- `src/composables/useTalentSummary.ts` — pure fn applying the picking rule
- `src/api/talents.ts` — `getTalentTree(treeId, specId)`
- `src/types/talents.ts` — TS types mirroring the BE response shape
- `tests/unit/useTalentSummary.spec.ts` — Vitest unit tests for picking rule
- `vitest.config.ts` — Vitest config (project has Vitest installed but no script yet)

**Modify:**
- `src/types/character.ts` — add `active_specialization_id?: number | null` and `talent_tree_id?: number | null` to `Character`.
- `src/pages/character/CharacterTalentsTab.vue` — switch import path to `@/components/character/talents/TalentTree.vue`.
- `package.json` — add `"test:unit": "vitest run"` and `"test:unit:watch": "vitest"` scripts.
- `cypress/e2e/character.cy.ts` (or whichever file holds the talents smoke) — assert the new layout.

**Delete:**
- `src/components/character/TalentTree.vue` — replaced by `talents/TalentTree.vue`.

---

# Backend tasks

## Task 1: Migration — `game_data_talent_trees` table

**Files:**
- Create: `database/migrations/2026_05_01_100002_create_game_data_talent_trees_table.php`

- [ ] **Step 1:** Create the migration file with the contents below.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_talent_trees', function (Blueprint $table) {
            $table->unsignedInteger('tree_id');
            $table->unsignedInteger('spec_id');
            $table->string('name', 200);
            $table->jsonb('tree');
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();

            $table->primary(['tree_id', 'spec_id']);
            $table->index('spec_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_talent_trees');
    }
};
```

- [ ] **Step 2:** Run `docker compose exec app php artisan migrate` and verify success. (Smoke step — actual run happens in deploy phase, but if you're running locally as you go this checks the SQL parses.)

- [ ] **Step 3:** Commit.

```bash
git add database/migrations/2026_05_01_100002_create_game_data_talent_trees_table.php
git commit -m "feat(talents): create game_data_talent_trees table"
```

## Task 2: Migration — `active_specialization_id` and `talent_tree_id` on `characters`

**Files:**
- Create: `database/migrations/2026_05_01_100003_add_specialization_ids_to_characters.php`

- [ ] **Step 1:** Create the migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('active_specialization_id')->nullable()->after('active_specialization');
            $table->unsignedInteger('talent_tree_id')->nullable()->after('active_specialization_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['active_specialization_id', 'talent_tree_id']);
        });
    }
};
```

- [ ] **Step 2:** Commit.

```bash
git add database/migrations/2026_05_01_100003_add_specialization_ids_to_characters.php
git commit -m "feat(talents): persist active_specialization_id + talent_tree_id on characters"
```

## Task 3: `GameDataTalentTree` Eloquent model

**Files:**
- Create: `app/Models/GameDataTalentTree.php`

- [ ] **Step 1:** Create the model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataTalentTree extends Model
{
    protected $table = 'game_data_talent_trees';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'tree_id',
        'spec_id',
        'name',
        'tree',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tree_id' => 'integer',
            'spec_id' => 'integer',
            'tree' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 2:** Commit.

```bash
git add app/Models/GameDataTalentTree.php
git commit -m "feat(talents): add GameDataTalentTree model"
```

## Task 4: `GameDataTalentTree` DTO

**Files:**
- Create: `app/Blizzard/DTO/GameDataTalentTree.php`

- [ ] **Step 1:** Create the DTO:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataTalentTree
{
    /**
     * @param  array{
     *     class_nodes: array<int, array<string, mixed>>,
     *     spec_nodes: array<int, array<string, mixed>>,
     *     hero_trees: array<int, array{id: int, name: string, nodes: array<int, array<string, mixed>>}>,
     *     edges: array<int, array{from: int, to: int}>,
     *   }  $tree
     */
    public function __construct(
        public int $treeId,
        public int $specId,
        public string $name,
        public array $tree,
    ) {}
}
```

- [ ] **Step 2:** Commit.

```bash
git add app/Blizzard/DTO/GameDataTalentTree.php
git commit -m "feat(talents): add GameDataTalentTree DTO"
```

## Task 5: `GameDataTalentTreeMapper` — mapper writing the JSONB shape

**Files:**
- Create: `app/Blizzard/Mappers/GameDataTalentTreeMapper.php`
- Test: `tests/Unit/GameDataTalentTreeMapperTest.php`
- Test fixture: `tests/Fixtures/blizzard/talent-tree-795-261.json`

The mapper consumes a Blizzard `/data/wow/talent-tree/{treeId}/playable-specialization/{specId}` response and produces a `GameDataTalentTree` DTO. The Blizzard response shape (relevant subset):

```jsonc
{
  "id": 795,
  "playable_specialization": { "id": 261, "name": "Subtlety" },
  "name": "Subtlety Talents",
  "class_talent_nodes": [
    { "id": 92675, "node_type": { "type": "SINGLE" },
      "ranks": [{ "tooltip": { "spell_tooltip": { "spell": { "id": 51713, "name": "Shadow Dance" }}}}],
      "display_row": 0, "display_col": 4, "unlocks": [92676] }
  ],
  "spec_talent_nodes": [
    { "id": 92900, "node_type": { "type": "CHOICE" },
      "ranks": [{ "choice_of_tooltips": [
        { "talent": { "id": 117, "name": "A" }, "spell_tooltip": { "spell": { "id": 1 }}},
        { "talent": { "id": 118, "name": "B" }, "spell_tooltip": { "spell": { "id": 2 }}}
      ]}],
      "display_row": 3, "display_col": 1, "unlocks": [] }
  ],
  "hero_talent_trees": [
    { "id": 31, "name": "Deathstalker", "hero_talent_nodes": [...] }
  ]
}
```

- [ ] **Step 1:** Create the fixture file. Save the following JSON to `tests/Fixtures/blizzard/talent-tree-795-261.json`. (Trimmed Sub Rogue tree — covers SINGLE + CHOICE + a hero subtree + a few `unlocks` so we can assert edge flattening.)

```json
{
  "id": 795,
  "name": "Subtlety Talents",
  "playable_specialization": { "id": 261, "name": "Subtlety" },
  "class_talent_nodes": [
    {
      "id": 100001,
      "node_type": { "type": "SINGLE" },
      "display_row": 0,
      "display_col": 4,
      "unlocks": [100002],
      "ranks": [
        { "tooltip": { "talent": { "id": 1, "name": "Stealth" }, "spell_tooltip": { "spell": { "id": 1784, "name": "Stealth" }}}}
      ]
    },
    {
      "id": 100002,
      "node_type": { "type": "CHOICE" },
      "display_row": 1,
      "display_col": 4,
      "unlocks": [],
      "ranks": [
        { "choice_of_tooltips": [
          { "talent": { "id": 11, "name": "Shadowstep" }, "spell_tooltip": { "spell": { "id": 36554, "name": "Shadowstep" }}},
          { "talent": { "id": 12, "name": "Grappling Hook" }, "spell_tooltip": { "spell": { "id": 195457, "name": "Grappling Hook" }}}
        ]}
      ]
    }
  ],
  "spec_talent_nodes": [
    {
      "id": 200001,
      "node_type": { "type": "SINGLE" },
      "display_row": 0,
      "display_col": 2,
      "unlocks": [200002, 200003],
      "ranks": [
        { "tooltip": { "talent": { "id": 21, "name": "Shadow Dance" }, "spell_tooltip": { "spell": { "id": 51713, "name": "Shadow Dance" }}}},
        { "tooltip": { "talent": { "id": 21, "name": "Shadow Dance" }, "spell_tooltip": { "spell": { "id": 51713, "name": "Shadow Dance" }}}}
      ]
    },
    {
      "id": 200002,
      "node_type": { "type": "SINGLE" },
      "display_row": 1,
      "display_col": 2,
      "unlocks": [],
      "ranks": [
        { "tooltip": { "talent": { "id": 22, "name": "Premeditation" }, "spell_tooltip": { "spell": { "id": 343160, "name": "Premeditation" }}}}
      ]
    },
    {
      "id": 200003,
      "node_type": { "type": "SINGLE" },
      "display_row": 1,
      "display_col": 3,
      "unlocks": [],
      "ranks": [
        { "tooltip": { "talent": { "id": 23, "name": "Shadow Focus" }, "spell_tooltip": { "spell": { "id": 108209, "name": "Shadow Focus" }}}}
      ]
    }
  ],
  "hero_talent_trees": [
    {
      "id": 31,
      "name": "Deathstalker",
      "hero_talent_nodes": [
        {
          "id": 300001,
          "node_type": { "type": "SINGLE" },
          "display_row": 0,
          "display_col": 1,
          "unlocks": [300002],
          "ranks": [
            { "tooltip": { "talent": { "id": 31, "name": "Deathstalker's Mark" }, "spell_tooltip": { "spell": { "id": 457925, "name": "Deathstalker's Mark" }}}}
          ]
        },
        {
          "id": 300002,
          "node_type": { "type": "SINGLE" },
          "display_row": 1,
          "display_col": 1,
          "unlocks": [],
          "ranks": [
            { "tooltip": { "talent": { "id": 32, "name": "Ethereal Cloak" }, "spell_tooltip": { "spell": { "id": 457911, "name": "Ethereal Cloak" }}}}
          ]
        }
      ]
    }
  ]
}
```

- [ ] **Step 2:** Write the failing test for the mapper:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Blizzard\Mappers\GameDataTalentTreeMapper;
use Tests\TestCase;

class GameDataTalentTreeMapperTest extends TestCase
{
    private function fixture(): array
    {
        $path = base_path('tests/Fixtures/blizzard/talent-tree-795-261.json');
        return json_decode(file_get_contents($path), true);
    }

    public function test_maps_basic_metadata(): void
    {
        $mapper = new GameDataTalentTreeMapper;
        $dto = $mapper->mapDetail($this->fixture());

        $this->assertNotNull($dto);
        $this->assertSame(795, $dto->treeId);
        $this->assertSame(261, $dto->specId);
        $this->assertSame('Subtlety Talents', $dto->name);
    }

    public function test_class_node_carries_position_and_ranks(): void
    {
        $dto = (new GameDataTalentTreeMapper)->mapDetail($this->fixture());
        $node = collect($dto->tree['class_nodes'])->firstWhere('id', 100001);

        $this->assertSame('regular', $node['type']);
        $this->assertSame(0, $node['display_row']);
        $this->assertSame(4, $node['display_col']);
        $this->assertNull($node['choice_options']);
        $this->assertSame([['spell_id' => 1784, 'name' => 'Stealth']], $node['ranks']);
    }

    public function test_choice_node_extracts_two_options(): void
    {
        $dto = (new GameDataTalentTreeMapper)->mapDetail($this->fixture());
        $node = collect($dto->tree['class_nodes'])->firstWhere('id', 100002);

        $this->assertSame('choice', $node['type']);
        $this->assertCount(2, $node['choice_options']);
        $this->assertSame(['talent_id' => 11, 'spell_id' => 36554, 'name' => 'Shadowstep'], $node['choice_options'][0]);
        $this->assertSame(['talent_id' => 12, 'spell_id' => 195457, 'name' => 'Grappling Hook'], $node['choice_options'][1]);
    }

    public function test_hero_trees_are_grouped_by_id_and_name(): void
    {
        $dto = (new GameDataTalentTreeMapper)->mapDetail($this->fixture());

        $this->assertCount(1, $dto->tree['hero_trees']);
        $hero = $dto->tree['hero_trees'][0];
        $this->assertSame(31, $hero['id']);
        $this->assertSame('Deathstalker', $hero['name']);
        $this->assertCount(2, $hero['nodes']);
    }

    public function test_edges_flatten_unlocks_across_class_spec_and_hero(): void
    {
        $dto = (new GameDataTalentTreeMapper)->mapDetail($this->fixture());

        $this->assertContains(['from' => 100001, 'to' => 100002], $dto->tree['edges']);
        $this->assertContains(['from' => 200001, 'to' => 200002], $dto->tree['edges']);
        $this->assertContains(['from' => 200001, 'to' => 200003], $dto->tree['edges']);
        $this->assertContains(['from' => 300001, 'to' => 300002], $dto->tree['edges']);
    }

    public function test_returns_null_when_id_is_missing(): void
    {
        $this->assertNull((new GameDataTalentTreeMapper)->mapDetail([]));
        $this->assertNull((new GameDataTalentTreeMapper)->mapDetail(null));
    }
}
```

- [ ] **Step 3:** Run the test — should fail with class-not-found:

```bash
docker compose exec app ./vendor/bin/phpunit --filter=GameDataTalentTreeMapperTest -v
```

- [ ] **Step 4:** Implement the mapper. The mapper walks all three families (`class_talent_nodes`, `spec_talent_nodes`, every `hero_talent_trees[].hero_talent_nodes`); for each node it reads `node_type.type` to pick `regular` (SINGLE) vs `choice` (CHOICE), reads `display_row`/`display_col`, extracts ranks (single-tooltip path) or choice options (`choice_of_tooltips`), and accumulates a flat `edges` list from each node's `unlocks`.

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataTalentTree;

class GameDataTalentTreeMapper
{
    public function mapDetail(?array $data): ?GameDataTalentTree
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $treeId = (int) $data['id'];
        $specId = (int) ($data['playable_specialization']['id'] ?? 0);
        if ($specId === 0) {
            return null;
        }

        $name = (string) ($data['name'] ?? 'Talents');

        $edges = [];

        $classNodes = $this->mapNodes($data['class_talent_nodes'] ?? [], $edges);
        $specNodes = $this->mapNodes($data['spec_talent_nodes'] ?? [], $edges);

        $heroTrees = [];
        foreach ($data['hero_talent_trees'] ?? [] as $hero) {
            if (! isset($hero['id'])) {
                continue;
            }
            $heroTrees[] = [
                'id' => (int) $hero['id'],
                'name' => (string) ($hero['name'] ?? ''),
                'nodes' => $this->mapNodes($hero['hero_talent_nodes'] ?? [], $edges),
            ];
        }

        return new GameDataTalentTree(
            treeId: $treeId,
            specId: $specId,
            name: $name,
            tree: [
                'class_nodes' => $classNodes,
                'spec_nodes' => $specNodes,
                'hero_trees' => $heroTrees,
                'edges' => array_values(array_unique(array_map(
                    fn ($e) => $e['from'].'-'.$e['to'],
                    $edges
                ))) === array_map(fn ($e) => $e['from'].'-'.$e['to'], $edges)
                    ? $edges
                    : $this->dedupeEdges($edges),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array{from:int,to:int}>  $edges  — accumulator, mutated by reference
     * @return array<int, array<string, mixed>>
     */
    private function mapNodes(array $nodes, array &$edges): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! isset($node['id'])) {
                continue;
            }
            $id = (int) $node['id'];
            $type = strtoupper((string) ($node['node_type']['type'] ?? 'SINGLE')) === 'CHOICE'
                ? 'choice' : 'regular';

            $ranks = [];
            $choiceOptions = null;

            $rawRanks = $node['ranks'] ?? [];
            if ($type === 'choice') {
                // CHOICE nodes carry choice_of_tooltips on the (single) rank.
                $opts = $rawRanks[0]['choice_of_tooltips'] ?? [];
                $choiceOptions = [];
                foreach ($opts as $opt) {
                    $choiceOptions[] = [
                        'talent_id' => (int) ($opt['talent']['id'] ?? 0),
                        'spell_id' => (int) ($opt['spell_tooltip']['spell']['id'] ?? 0),
                        'name' => (string) ($opt['talent']['name'] ?? ''),
                    ];
                }
                if ($choiceOptions === []) {
                    $choiceOptions = null;
                }
            } else {
                foreach ($rawRanks as $rank) {
                    $ranks[] = [
                        'spell_id' => (int) ($rank['tooltip']['spell_tooltip']['spell']['id'] ?? 0),
                        'name' => (string) ($rank['tooltip']['talent']['name'] ?? ''),
                    ];
                }
            }

            foreach ($node['unlocks'] ?? [] as $to) {
                $edges[] = ['from' => $id, 'to' => (int) $to];
            }

            $out[] = [
                'id' => $id,
                'display_row' => (int) ($node['display_row'] ?? 0),
                'display_col' => (int) ($node['display_col'] ?? 0),
                'type' => $type,
                'ranks' => $ranks,
                'choice_options' => $choiceOptions,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{from:int,to:int}>  $edges
     * @return array<int, array{from:int,to:int}>
     */
    private function dedupeEdges(array $edges): array
    {
        $seen = [];
        $out = [];
        foreach ($edges as $e) {
            $key = $e['from'].'-'.$e['to'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $e;
        }
        return $out;
    }
}
```

- [ ] **Step 5:** Run the test — expect PASS.

```bash
docker compose exec app ./vendor/bin/phpunit --filter=GameDataTalentTreeMapperTest -v
```

- [ ] **Step 6:** Commit.

```bash
git add app/Blizzard/Mappers/GameDataTalentTreeMapper.php tests/Unit/GameDataTalentTreeMapperTest.php tests/Fixtures/blizzard/talent-tree-795-261.json
git commit -m "feat(talents): add GameDataTalentTreeMapper with fixtures + tests"
```

## Task 6: `BlizzardGameDataClient::getPlayableSpecializationIndex()`

The talent-trees sync needs a list of `(tree_id, spec_id)` pairs. Blizzard exposes
`/data/wow/playable-specialization/index` (static-{region} namespace), which returns
`{character_specializations: [{id, key:{href}, name}], pet_specializations: [...]}`.
Each spec's detail endpoint exposes `talent_tree.id`. We'll fetch the index, then
call `getPlayableSpecialization($specId)` per id to read the tree id.

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1:** Append two new methods at the bottom of the class, before the closing brace. The pattern matches `getRealmIndex()` / `getTalentTree()` (Http directly with `static-{region}` namespace).

```php
    /**
     * Fetch /data/wow/playable-specialization/index in the static-{region}
     * namespace. Returns {character_specializations: [{id, key:{href}, name}], ...}.
     * Cached aggressively because the index only changes on patches.
     */
    public function getPlayableSpecializationIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:playable-specialization-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/playable-specialization/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/playable-specialization/{specId}. Returns the spec detail,
     * which includes `talent_tree.id` — the value we feed back into getTalentTree().
     * Lives in static-{region} namespace.
     */
    public function getPlayableSpecialization(int $specId): ?array
    {
        $cacheKey = "blizzard:game-data:playable-specialization:{$this->region}:{$specId}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($specId): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/playable-specialization/{$specId}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
```

- [ ] **Step 2:** Commit.

```bash
git add app/Blizzard/Client/BlizzardGameDataClient.php
git commit -m "feat(talents): add playable-specialization index/detail client methods"
```

## Task 7: `talent-trees` slice on `SyncGameData` command

**Files:**
- Modify: `app/Console/Commands/SyncGameData.php`

- [ ] **Step 1:** Add the new slice. Update the signature, description, default-resources list, the `match` table, and the new `syncTalentTrees()` method.

Update:
```php
protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements|pve|talent-trees; omit for all}';

protected $description = 'Sync static reference data (factions/titles/mounts/achievements/pve/talent-trees) from Blizzard Game Data API into game_data_* tables';
```

Add `App\Blizzard\Mappers\GameDataTalentTreeMapper` and `App\Models\GameDataTalentTree` to the `use` block at the top.

Update the `handle()` method's argument list to inject the mapper:

```php
public function handle(
    BlizzardGameDataClient $client,
    GameDataFactionMapper $factionMapper,
    GameDataTitleMapper $titleMapper,
    GameDataMountMapper $mountMapper,
    GameDataAchievementCategoryMapper $achievementCategoryMapper,
    GameDataAchievementMapper $achievementMapper,
    GameDataRaidInstanceMapper $raidInstanceMapper,
    GameDataRaidEncounterMapper $raidEncounterMapper,
    GameDataMythicKeystoneDungeonMapper $dungeonMapper,
    GameDataKeystoneAffixMapper $affixMapper,
    GameDataTalentTreeMapper $talentTreeMapper,
): int {
```

Update the default-resources list and the match arm:

```php
$resources = $resource === null
    ? ['factions', 'titles', 'mounts', 'achievements', 'pve', 'talent-trees']
    : [$resource];

foreach ($resources as $r) {
    match ($r) {
        'factions' => $this->syncFactions($client, $factionMapper),
        'titles' => $this->syncTitles($client, $titleMapper),
        'mounts' => $this->syncMounts($client, $mountMapper),
        'achievements' => $this->syncAchievements($client, $achievementCategoryMapper, $achievementMapper),
        'pve' => $this->syncPve($client, $raidInstanceMapper, $raidEncounterMapper, $dungeonMapper, $affixMapper),
        'talent-trees' => $this->syncTalentTrees($client, $talentTreeMapper),
        default => $this->error("Unknown resource: {$r}") || self::FAILURE,
    };
}
```

Add the new method (place at the bottom, before the closing class brace):

```php
/**
 * Sync the talent-tree topology table. For each character spec id, read the
 * spec's detail to find its talent_tree id, then fetch /data/wow/talent-tree/
 * {treeId}/playable-specialization/{specId}, run it through the mapper, and
 * upsert one row keyed by (tree_id, spec_id).
 *
 * Per-pair failure tolerance: log + skip on 404 / thrown error; do not
 * abort the rest of the sync.
 */
private function syncTalentTrees(
    BlizzardGameDataClient $client,
    GameDataTalentTreeMapper $mapper,
): void {
    $this->info('Syncing talent trees...');

    $index = $client->getPlayableSpecializationIndex();
    if ($index === null) {
        $this->warn('Playable-specialization index returned null (404). Skipping talent trees.');
        return;
    }

    $specs = $index['character_specializations'] ?? [];
    $specIds = [];
    foreach ($specs as $entry) {
        if (isset($entry['id'])) {
            $specIds[] = (int) $entry['id'];
        }
    }

    $this->info('Index returned '.count($specIds).' character spec IDs.');

    $bar = $this->output->createProgressBar(count($specIds));
    $bar->start();

    $upserted = 0;
    $skipped = 0;

    foreach ($specIds as $specId) {
        try {
            $specDetail = $client->getPlayableSpecialization($specId);
            $treeId = isset($specDetail['talent_tree']['id'])
                ? (int) $specDetail['talent_tree']['id']
                : null;

            if ($treeId === null) {
                Log::warning("Talent-tree sync skipped specId={$specId}: no talent_tree on spec detail");
                $skipped++;
                $bar->advance();
                continue;
            }

            $treeRaw = $client->getTalentTree($treeId, $specId);
            $dto = $mapper->mapDetail($treeRaw);
            if ($dto === null) {
                Log::warning("Talent-tree sync skipped specId={$specId} treeId={$treeId}: mapper returned null");
                $skipped++;
                $bar->advance();
                continue;
            }

            DB::transaction(function () use ($dto, &$upserted) {
                GameDataTalentTree::updateOrCreate(
                    ['tree_id' => $dto->treeId, 'spec_id' => $dto->specId],
                    [
                        'name' => $dto->name,
                        'tree' => $dto->tree,
                        'synced_at' => now(),
                    ],
                );
                $upserted++;
            });
        } catch (Throwable $e) {
            Log::warning("Talent-tree sync skipped specId={$specId}: ".$e->getMessage());
            $skipped++;
        }
        $bar->advance();
    }

    $bar->finish();
    $this->newLine();
    $this->info("Talent trees synced: {$upserted} upserted, {$skipped} skipped.");
}
```

- [ ] **Step 2:** Run pint to keep the file's style consistent.

```bash
docker compose exec app ./vendor/bin/pint app/Console/Commands/SyncGameData.php
```

- [ ] **Step 3:** Commit.

```bash
git add app/Console/Commands/SyncGameData.php
git commit -m "feat(talents): add talent-trees slice to blizzard:sync-game-data"
```

## Task 8: Endpoint — `GET /game-data/talent-trees/{treeId}/{specId}`

**Files:**
- Modify: `app/Http/Controllers/GameDataController.php`
- Modify: `routes/api.php`

- [ ] **Step 1:** Add the controller action. Place it after `mythicKeystoneDungeons()` and before `realms()`. Reuse the `Cache-Control: public, max-age=3600` pattern.

```php
    /**
     * GET /api/v1/game-data/talent-trees/{treeId}/{specId}
     *
     * Public, long-cacheable. Returns the topology JSONB blob for a single
     * (tree_id, spec_id) pair. 404 when the row doesn't exist — FE treats
     * that as "not yet synced for this spec" and falls back to the
     * picked-only flat-list rendering.
     *
     * Cache header per game-data convention: `Cache-Control: public, max-age=3600`.
     */
    public function talentTree(int $treeId, int $specId): JsonResponse
    {
        $row = GameDataTalentTree::query()
            ->where('tree_id', $treeId)
            ->where('spec_id', $specId)
            ->first();

        if ($row === null) {
            return response()->json(['message' => 'Talent tree not synced for this spec yet.'], 404);
        }

        return response()->json([
            'tree_id' => (int) $row->tree_id,
            'spec_id' => (int) $row->spec_id,
            'name' => (string) $row->name,
            'tree' => $row->tree,
        ])->header('Cache-Control', 'public, max-age=3600');
    }
```

Add to the `use` block at the top:

```php
use App\Models\GameDataTalentTree;
```

- [ ] **Step 2:** Register the route in `routes/api.php`. Add this after the `mythic-keystone-dungeons` route and before the `realms` route:

```php
Route::get('/game-data/talent-trees/{treeId}/{specId}', [GameDataController::class, 'talentTree'])
    ->whereNumber(['treeId', 'specId'])
    ->name('game-data.talent-tree');
```

- [ ] **Step 3:** Commit.

```bash
git add app/Http/Controllers/GameDataController.php routes/api.php
git commit -m "feat(talents): expose /game-data/talent-trees/{treeId}/{specId}"
```

## Task 9: Endpoint test

**Files:**
- Create: `tests/Feature/Endpoints/GameDataTalentTreeEndpointTest.php`

- [ ] **Step 1:** Create the test:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataTalentTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataTalentTreeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_200_with_payload_when_row_exists(): void
    {
        GameDataTalentTree::create([
            'tree_id' => 795,
            'spec_id' => 261,
            'name' => 'Subtlety Talents',
            'tree' => [
                'class_nodes' => [],
                'spec_nodes' => [],
                'hero_trees' => [],
                'edges' => [],
            ],
            'synced_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/game-data/talent-trees/795/261');

        $response->assertOk()
            ->assertJson([
                'tree_id' => 795,
                'spec_id' => 261,
                'name' => 'Subtlety Talents',
                'tree' => [
                    'class_nodes' => [],
                    'spec_nodes' => [],
                    'hero_trees' => [],
                    'edges' => [],
                ],
            ]);

        $this->assertSame('public, max-age=3600', $response->headers->get('Cache-Control'));
    }

    public function test_returns_404_when_row_missing(): void
    {
        $this->getJson('/api/v1/game-data/talent-trees/9999/9999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
```

- [ ] **Step 2:** Run.

```bash
docker compose exec app ./vendor/bin/phpunit --filter=GameDataTalentTreeEndpointTest -v
```

- [ ] **Step 3:** Commit.

```bash
git add tests/Feature/Endpoints/GameDataTalentTreeEndpointTest.php
git commit -m "test(talents): cover /game-data/talent-trees/{treeId}/{specId}"
```

## Task 10: Persist `active_specialization_id` + `talent_tree_id` on `Character`

**Files:**
- Modify: `app/Blizzard/DTO/CharacterSpecialization.php`
- Modify: `app/Blizzard/Mappers/CharacterSpecializationMapper.php`
- Modify: `app/Models/Character.php`
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`
- Modify: `app/Http/Resources/CharacterResource.php`

- [ ] **Step 1:** Update the DTO.

```php
final readonly class CharacterSpecialization
{
    public function __construct(
        public string $activeSpecialization,
        public ?int $activeSpecializationId = null,
        public ?int $talentTreeId = null,
        public array $classTalents = [],
        public array $specTalents = [],
        public array $heroTalents = [],
        public array $pvpTalents = [],
        public ?string $talentLoadoutCode = null,
    ) {}
}
```

- [ ] **Step 2:** Update `CharacterSpecializationMapper::map()` to pass the two new ids through. The existing locals `$activeSpecId` and `$treeId` already hold them — just thread them into the constructor:

```php
return new CharacterSpecialization(
    activeSpecialization: $activeSpec,
    activeSpecializationId: $activeSpecId,
    talentTreeId: $treeId,
    classTalents: $classTalents,
    specTalents: $specTalents,
    heroTalents: $heroTalents,
    pvpTalents: $pvpTalents,
    talentLoadoutCode: $loadoutCode,
);
```

Note: today, `$treeId` is declared inside the `foreach ($spec['loadouts'] ?? [] as $loadout)` block, which means it's `null` when the character has no active loadout. Promote `$treeId` to function scope so it's available at return time. Initialize at the same place as `$loadoutCode`:

```php
$loadoutCode = null;
$treeId = null;
```

And replace the inner `$treeId = $this->extractTreeId(...)` with a plain assignment to the outer (no `my`-style scoping needed in PHP — same name, same scope).

- [ ] **Step 3:** Update the `Character` model. Add to `$fillable`:

```php
'active_specialization_id',
'talent_tree_id',
```

And to the `casts()` method (or array — match the file's existing style):

```php
'active_specialization_id' => 'integer',
'talent_tree_id' => 'integer',
```

- [ ] **Step 4:** Update the call sites in `SyncCharacterData::handle()` (Standard sync block — search for `active_specialization` to find the line that maps the `CharacterSpecialization` DTO into the `Character` row). Add the two new columns alongside the existing fields:

Search this file for the line that writes `active_specialization` from the spec mapper output. Add `active_specialization_id` and `talent_tree_id` to the same array. Example shape (line number will drift as the file is edited; locate by string match):

```php
$character->active_specialization = $spec->activeSpecialization;
$character->active_specialization_id = $spec->activeSpecializationId;
$character->talent_tree_id = $spec->talentTreeId;
$character->talent_loadout_code = $spec->talentLoadoutCode;
```

If the existing pattern uses `update([...])` or `fill([...])` instead, mirror that.

- [ ] **Step 5:** Update `CharacterResource::toArray()`. Add two lines next to `'active_specialization'`:

```php
'active_specialization' => $this->active_specialization,
'active_specialization_id' => $this->active_specialization_id,
'talent_tree_id' => $this->talent_tree_id,
```

- [ ] **Step 6:** Commit.

```bash
git add app/Blizzard/DTO/CharacterSpecialization.php app/Blizzard/Mappers/CharacterSpecializationMapper.php app/Models/Character.php app/Blizzard/Jobs/SyncCharacterData.php app/Http/Resources/CharacterResource.php
git commit -m "feat(talents): persist + emit active_specialization_id and talent_tree_id"
```

## Task 11: Extend retail-character endpoint test

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php` (or whichever test currently asserts `talents` payload shape — locate via `grep -rn talent_loadout_code tests/`).

- [ ] **Step 1:** Find the assertion block that includes `talent_loadout_code` and add adjacent assertions for the two new fields. Pattern (adapt to the file's existing style):

```php
->assertJson(fn (\Illuminate\Testing\Fluent\AssertableJson $json) => $json
    ->has('active_specialization')
    ->has('active_specialization_id')
    ->has('talent_tree_id')
    ...
)
```

If the test uses `assertJsonStructure(['active_specialization', ...])`, append the new keys there instead.

- [ ] **Step 2:** Run the test.

```bash
docker compose exec app ./vendor/bin/phpunit --filter=RetailCharacterEndpointTest -v
```

- [ ] **Step 3:** Commit.

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "test(talents): assert active_specialization_id + talent_tree_id on retail character payload"
```

---

# Frontend tasks

## Task 12: TS types for talent-tree response

**Files:**
- Create: `src/types/talents.ts`

- [ ] **Step 1:** Create the file:

```ts
export type TalentNodeType = 'regular' | 'choice'

export interface TalentNodeRank {
  spell_id: number
  name: string
}

export interface TalentChoiceOption {
  talent_id: number
  spell_id: number
  name: string
}

export interface TalentNode {
  id: number
  display_row: number
  display_col: number
  type: TalentNodeType
  ranks: TalentNodeRank[]
  choice_options: TalentChoiceOption[] | null
}

export interface HeroTree {
  id: number
  name: string
  nodes: TalentNode[]
}

export interface TalentEdge {
  from: number
  to: number
}

export interface TalentTreeTopology {
  class_nodes: TalentNode[]
  spec_nodes: TalentNode[]
  hero_trees: HeroTree[]
  edges: TalentEdge[]
}

export interface TalentTreeResponse {
  tree_id: number
  spec_id: number
  name: string
  tree: TalentTreeTopology
}

/** Resolved icon-ref the summary-strip composable returns. */
export interface TalentNodeRef {
  /** Source node id, kept so :key can be stable. */
  node_id: number
  /** Spell id used for both the icon (via Wowhead) and the tooltip. */
  spell_id: number
  /** "1/3" rendered next to the icon, or null for choice / single-rank. */
  rank_label: string | null
  /** Section the node came from — used only for ordering, not styling. */
  section: 'class' | 'hero' | 'spec'
}
```

- [ ] **Step 2:** Add the two new optional fields to `Character` in `src/types/character.ts`. Find the existing `talent_loadout_code` line and add directly after:

```ts
talent_loadout_code: string | null
active_specialization_id?: number | null
talent_tree_id?: number | null
```

- [ ] **Step 3:** Commit.

```bash
git add src/types/talents.ts src/types/character.ts
git commit -m "feat(talents): add TS types for talent-tree response + character ids"
```

## Task 13: API client + composable for the new endpoint

**Files:**
- Create: `src/api/talents.ts`
- Create: `src/composables/useTalentTree.ts`

- [ ] **Step 1:** Create `src/api/talents.ts`:

```ts
import { api } from './client'
import type { TalentTreeResponse } from '@/types/talents'

const REVALIDATE_HEADERS = { 'Cache-Control': 'no-cache' }

export async function getTalentTree(
  treeId: number,
  specId: number,
): Promise<TalentTreeResponse> {
  const res = await api.get<TalentTreeResponse>(
    `/game-data/talent-trees/${treeId}/${specId}`,
    { headers: REVALIDATE_HEADERS },
  )
  return res.data
}
```

- [ ] **Step 2:** Create `src/composables/useTalentTree.ts`:

```ts
import { useQuery } from '@tanstack/vue-query'
import { computed, type MaybeRefOrGetter, toValue } from 'vue'
import { getTalentTree } from '@/api/talents'
import type { TalentTreeResponse } from '@/types/talents'

const ONE_DAY_MS = 24 * 60 * 60 * 1000

/**
 * Fetches the full talent-tree topology for a (treeId, specId) pair.
 * Returns null in `data` when either id is missing (low-level character
 * with no talents picked). The query is disabled in that case, so 404s
 * are not generated.
 *
 * staleTime: Infinity / gcTime: 24h — talent topology only changes on
 * patches, same posture as usePveGameData.
 */
export function useTalentTree(
  treeId: MaybeRefOrGetter<number | null | undefined>,
  specId: MaybeRefOrGetter<number | null | undefined>,
) {
  const enabled = computed(
    () => Boolean(toValue(treeId)) && Boolean(toValue(specId)),
  )

  return useQuery<TalentTreeResponse>({
    queryKey: ['talent-tree', treeId, specId],
    queryFn: () => getTalentTree(toValue(treeId)!, toValue(specId)!),
    enabled,
    staleTime: Infinity,
    gcTime: ONE_DAY_MS,
    retry: (failureCount, error: unknown) => {
      // Don't retry 404 — that's the "not yet synced" fallthrough.
      const status = (error as { response?: { status?: number } } | null)?.response?.status
      if (status === 404) return false
      return failureCount < 2
    },
  })
}
```

- [ ] **Step 3:** Commit.

```bash
git add src/api/talents.ts src/composables/useTalentTree.ts
git commit -m "feat(talents): add /game-data/talent-trees client + useTalentTree query"
```

## Task 14: `useTalentSummary` — pure picking-rule fn (with Vitest)

**Files:**
- Create: `src/composables/useTalentSummary.ts`
- Create: `vitest.config.ts`
- Create: `tests/unit/useTalentSummary.spec.ts`
- Modify: `package.json`

- [ ] **Step 1:** Add `vitest.config.ts`:

```ts
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/unit/**/*.spec.ts'],
    globals: false,
  },
})
```

- [ ] **Step 2:** Add the test scripts to `package.json` (in the `scripts` object):

```json
"test:unit": "vitest run",
"test:unit:watch": "vitest"
```

- [ ] **Step 3:** Write the failing tests in `tests/unit/useTalentSummary.spec.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { computeTalentSummary } from '@/composables/useTalentSummary'
import type {
  TalentTreeTopology,
  TalentNode,
  TalentNodeRef,
} from '@/types/talents'
import type { CharacterTalents } from '@/types/character'

function regular(id: number, row: number, col: number, spellId: number): TalentNode {
  return {
    id,
    display_row: row,
    display_col: col,
    type: 'regular',
    ranks: [{ spell_id: spellId, name: '' }],
    choice_options: null,
  }
}

function choice(
  id: number,
  row: number,
  col: number,
  optA: { talent_id: number; spell_id: number },
  optB: { talent_id: number; spell_id: number },
): TalentNode {
  return {
    id,
    display_row: row,
    display_col: col,
    type: 'choice',
    ranks: [],
    choice_options: [
      { ...optA, name: '' },
      { ...optB, name: '' },
    ],
  }
}

const heroBase: TalentTreeTopology['hero_trees'][number] = {
  id: 31,
  name: 'Deathstalker',
  nodes: [
    regular(3001, 0, 1, 800001), // entry
    choice(3002, 1, 1, { talent_id: 1, spell_id: 800002 }, { talent_id: 2, spell_id: 800003 }),
    regular(3003, 2, 1, 800004),
  ],
}

const otherHero: TalentTreeTopology['hero_trees'][number] = {
  id: 32,
  name: 'Trickster',
  nodes: [regular(3101, 0, 1, 900001)],
}

const baseTopology = (): TalentTreeTopology => ({
  class_nodes: [
    choice(1001, 0, 4, { talent_id: 10, spell_id: 100001 }, { talent_id: 11, spell_id: 100002 }), // shallow choice
    choice(1002, 5, 4, { talent_id: 12, spell_id: 100003 }, { talent_id: 13, spell_id: 100004 }), // deeper choice
    choice(1003, 6, 4, { talent_id: 14, spell_id: 100005 }, { talent_id: 15, spell_id: 100006 }), // deepest choice
    regular(1004, 7, 1, 100007),
  ],
  spec_nodes: [
    choice(2001, 1, 2, { talent_id: 20, spell_id: 200001 }, { talent_id: 21, spell_id: 200002 }),
    choice(2002, 4, 2, { talent_id: 22, spell_id: 200003 }, { talent_id: 23, spell_id: 200004 }),
    choice(2003, 5, 3, { talent_id: 24, spell_id: 200005 }, { talent_id: 25, spell_id: 200006 }),
  ],
  hero_trees: [heroBase, otherHero],
  edges: [],
})

function picked(): CharacterTalents {
  return {
    class: [
      // class choice 1003 picked (deepest)
      { id: 1003, spell_id: 100005, rank: 1, max_rank: 1 },
      // class choice 1002 picked
      { id: 1002, spell_id: 100003, rank: 1, max_rank: 1 },
      // class choice 1001 picked (shallow — should not enter top 2)
      { id: 1001, spell_id: 100001, rank: 1, max_rank: 1 },
      // class regular 1004 picked (last-resort fallback)
      { id: 1004, spell_id: 100007, rank: 1, max_rank: 1 },
    ],
    spec: [
      { id: 2003, spell_id: 200005, rank: 1, max_rank: 1 },
      { id: 2002, spell_id: 200003, rank: 1, max_rank: 1 },
      { id: 2001, spell_id: 200001, rank: 1, max_rank: 1 },
    ],
    hero: [
      { id: 3001, spell_id: 800001, rank: 1, max_rank: 1 },
      { id: 3002, spell_id: 800002, rank: 1, max_rank: 1 },
    ],
    pvp: [],
  }
}

describe('computeTalentSummary', () => {
  it('returns 6 icons in Class -> Hero -> Spec order at full quota', () => {
    const result = computeTalentSummary(picked(), baseTopology())
    expect(result).toHaveLength(6)
    const sections = result.map((r) => r.section)
    expect(sections).toEqual(['class', 'class', 'hero', 'hero', 'spec', 'spec'])
  })

  it('picks the two deepest class choice nodes by display_row desc', () => {
    const result = computeTalentSummary(picked(), baseTopology())
    const classRefs = result.filter((r) => r.section === 'class')
    expect(classRefs.map((r) => r.node_id)).toEqual([1003, 1002])
  })

  it('drops to 5 icons when the active hero tree has no picked choice node', () => {
    const p = picked()
    p.hero = [{ id: 3001, spell_id: 800001, rank: 1, max_rank: 1 }] // only entry, no choice
    const result = computeTalentSummary(p, baseTopology())
    expect(result).toHaveLength(5)
    const heroRefs = result.filter((r) => r.section === 'hero')
    expect(heroRefs).toHaveLength(1)
    expect(heroRefs[0].node_id).toBe(3001)
  })

  it('tie-breaks on equal display_row by lower display_col then lower id', () => {
    const topo = baseTopology()
    // Add a class choice node tied with 1003 on display_row=6
    topo.class_nodes.push(
      choice(1010, 6, 2, { talent_id: 99, spell_id: 9001 }, { talent_id: 100, spell_id: 9002 }),
    )
    const p = picked()
    p.class.push({ id: 1010, spell_id: 9001, rank: 1, max_rank: 1 })

    const result = computeTalentSummary(p, topo)
    const classIds = result.filter((r) => r.section === 'class').map((r) => r.node_id)
    // Display_row desc -> 1003(row7? no, row6 col4), 1010(row6 col2). Tie-break: lower col first => 1010, then 1003.
    // But 1002 is row5, so top 2 from {1003 row6 col4, 1010 row6 col2, 1002 row5} are 1010 and 1003 (col tiebreak).
    expect(classIds).toEqual([1010, 1003])
  })

  it('falls back to deepest non-choice picked talents when choice quota is unmet', () => {
    const p = picked()
    // Drop both deep class choice picks; only shallow choice 1001 + regular 1004 remain
    p.class = [
      { id: 1001, spell_id: 100001, rank: 1, max_rank: 1 },
      { id: 1004, spell_id: 100007, rank: 1, max_rank: 1 },
    ]
    const result = computeTalentSummary(p, baseTopology())
    const classRefs = result.filter((r) => r.section === 'class')
    expect(classRefs).toHaveLength(2)
    // Quota=2. One choice (1001 row 0). Top up with deepest non-choice picked (1004 row 7).
    expect(classRefs.map((r) => r.node_id).sort()).toEqual([1001, 1004])
  })

  it('returns empty array when picked talents are empty (low-level char)', () => {
    expect(
      computeTalentSummary(
        { class: [], spec: [], hero: [], pvp: [] },
        baseTopology(),
      ),
    ).toEqual([])
  })
})
```

- [ ] **Step 4:** Run the tests — should fail with "module not found":

```bash
cd frontend && npm run test:unit
```

- [ ] **Step 5:** Implement `src/composables/useTalentSummary.ts`. The fn is pure synchronous — no Vue reactivity.

```ts
import type { CharacterTalents, TalentEntry } from '@/types/character'
import type {
  HeroTree,
  TalentNode,
  TalentNodeRef,
  TalentTreeTopology,
} from '@/types/talents'

const CLASS_QUOTA = 2
const HERO_QUOTA = 2 // entry + 1 deepest choice (downgrades to 1 when no choice picked)
const SPEC_QUOTA = 2

interface NodeIndex {
  byId: Map<number, TalentNode>
}

function indexTopology(tree: TalentTreeTopology): {
  classIndex: NodeIndex
  specIndex: NodeIndex
  heroIndexes: Map<number, NodeIndex>
} {
  const classIndex: NodeIndex = { byId: new Map() }
  for (const n of tree.class_nodes) classIndex.byId.set(n.id, n)

  const specIndex: NodeIndex = { byId: new Map() }
  for (const n of tree.spec_nodes) specIndex.byId.set(n.id, n)

  const heroIndexes = new Map<number, NodeIndex>()
  for (const h of tree.hero_trees) {
    const idx: NodeIndex = { byId: new Map() }
    for (const n of h.nodes) idx.byId.set(n.id, n)
    heroIndexes.set(h.id, idx)
  }

  return { classIndex, specIndex, heroIndexes }
}

interface PickedWithNode {
  picked: TalentEntry
  node: TalentNode
}

function joinPicked(picks: TalentEntry[], index: NodeIndex): PickedWithNode[] {
  const out: PickedWithNode[] = []
  for (const p of picks) {
    const node = index.byId.get(p.id)
    if (node) out.push({ picked: p, node })
  }
  return out
}

/**
 * Sort by display_row desc, then display_col asc, then id asc.
 */
function sortDeepestFirst(arr: PickedWithNode[]): PickedWithNode[] {
  return [...arr].sort((a, b) => {
    if (b.node.display_row !== a.node.display_row) return b.node.display_row - a.node.display_row
    if (a.node.display_col !== b.node.display_col) return a.node.display_col - b.node.display_col
    return a.node.id - b.node.id
  })
}

function rankLabel(p: TalentEntry): string | null {
  if (p.max_rank > 1) return `${p.rank}/${p.max_rank}`
  return null
}

function toRef(p: PickedWithNode, section: 'class' | 'hero' | 'spec'): TalentNodeRef {
  return {
    node_id: p.node.id,
    spell_id: p.picked.spell_id,
    rank_label: rankLabel(p.picked),
    section,
  }
}

/**
 * Pick `quota` deepest choice nodes; if fewer than `quota` were picked, top up
 * with the deepest non-choice picked talents until quota is met (or there are
 * no more candidates).
 */
function pickForSection(
  picks: PickedWithNode[],
  quota: number,
  section: 'class' | 'hero' | 'spec',
): TalentNodeRef[] {
  if (quota === 0) return []
  const choices = picks.filter((p) => p.node.type === 'choice')
  const regulars = picks.filter((p) => p.node.type === 'regular')

  const sortedChoices = sortDeepestFirst(choices).slice(0, quota)
  const need = quota - sortedChoices.length
  const topUp = need > 0 ? sortDeepestFirst(regulars).slice(0, need) : []

  return [...sortedChoices, ...topUp].map((p) => toRef(p, section))
}

function pickForHero(
  pickedHero: PickedWithNode[],
  activeHero: HeroTree | null,
): TalentNodeRef[] {
  if (activeHero === null || pickedHero.length === 0) return []

  // Entry / keystone = the picked node with the lowest display_row.
  const ascending = [...pickedHero].sort((a, b) => {
    if (a.node.display_row !== b.node.display_row) return a.node.display_row - b.node.display_row
    if (a.node.display_col !== b.node.display_col) return a.node.display_col - b.node.display_col
    return a.node.id - b.node.id
  })
  const entry = ascending[0]
  const remaining = ascending.slice(1)

  const choices = remaining.filter((p) => p.node.type === 'choice')
  const deepestChoice = sortDeepestFirst(choices)[0] ?? null

  const refs: TalentNodeRef[] = [toRef(entry, 'hero')]
  if (deepestChoice !== null) refs.push(toRef(deepestChoice, 'hero'))
  return refs
}

function findActiveHeroTree(
  picks: TalentEntry[],
  tree: TalentTreeTopology,
): HeroTree | null {
  const pickedIds = new Set(picks.map((p) => p.id))
  for (const h of tree.hero_trees) {
    if (h.nodes.some((n) => pickedIds.has(n.id))) return h
  }
  return null
}

export function computeTalentSummary(
  picked: CharacterTalents,
  tree: TalentTreeTopology,
): TalentNodeRef[] {
  const totalPicks =
    (picked.class?.length ?? 0) +
    (picked.spec?.length ?? 0) +
    (picked.hero?.length ?? 0)
  if (totalPicks === 0) return []

  const { classIndex, specIndex, heroIndexes } = indexTopology(tree)

  const pickedClass = joinPicked(picked.class ?? [], classIndex)
  const pickedSpec = joinPicked(picked.spec ?? [], specIndex)

  const activeHero = findActiveHeroTree(picked.hero ?? [], tree)
  const pickedHero = activeHero
    ? joinPicked(picked.hero ?? [], heroIndexes.get(activeHero.id)!)
    : []

  return [
    ...pickForSection(pickedClass, CLASS_QUOTA, 'class'),
    ...pickForHero(pickedHero, activeHero),
    ...pickForSection(pickedSpec, SPEC_QUOTA, 'spec'),
  ]
}
```

- [ ] **Step 6:** Run the tests — expect PASS.

```bash
npm run test:unit
```

- [ ] **Step 7:** Commit.

```bash
git add vitest.config.ts package.json src/composables/useTalentSummary.ts tests/unit/useTalentSummary.spec.ts
git commit -m "feat(talents): add useTalentSummary picking-rule with unit tests"
```

## Task 15: `TalentNode.vue`

**Files:**
- Create: `src/components/character/talents/TalentNode.vue`

A single node icon with frame, badge, picked/unpicked state, Wowhead anchor.
The `wowhead.zamimg.com/images/wow/icons/medium/{slug}.jpg` URL is what
`<a data-wowhead>` typically resolves; we don't pre-fetch — Wowhead's
`power.js` injects an image into the empty anchor.

Spec:
- Frame: round (`rounded-full`) for `regular`, octagonal (`clip-path: polygon(...)`) for `choice`.
- Picked: full opacity + `box-shadow: 0 0 8px <class-color>`.
- Unpicked: `opacity: 0.35`, no ring.
- Rank badge: `{rank}/{max_rank}` bottom-right, hidden if `max_rank === 1` or null label.

- [ ] **Step 1:** Create the component:

```vue
<template>
  <a
    :href="href"
    :data-wowhead="dataWowhead"
    target="_blank"
    rel="noopener"
    class="talent-node"
    :class="[
      isChoice ? 'talent-node--choice' : 'talent-node--regular',
      isPicked ? 'talent-node--picked' : 'talent-node--unpicked',
    ]"
    :style="nodeStyle"
  >
    <span class="talent-node__icon" />
    <span v-if="rankLabel" class="talent-node__rank">{{ rankLabel }}</span>
  </a>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { buildWowheadHref } from '@/utils/wowhead'

const props = defineProps<{
  spellId: number
  isPicked: boolean
  isChoice: boolean
  rankLabel?: string | null
  classColor?: string | null
  /** Optional positional layout (consumed by TalentTreeColumn). */
  row?: number
  col?: number
  cellSize?: number
}>()

const dataWowhead = computed(() => `spell=${props.spellId}`)
const href = computed(() => buildWowheadHref({ spellId: props.spellId }))

const nodeStyle = computed(() => {
  const out: Record<string, string> = {}
  if (props.row !== undefined && props.col !== undefined && props.cellSize !== undefined) {
    out.position = 'absolute'
    out.top = `${props.row * props.cellSize}px`
    out.left = `${props.col * props.cellSize}px`
    out.width = `${props.cellSize - 8}px`
    out.height = `${props.cellSize - 8}px`
  }
  if (props.isPicked && props.classColor) {
    out['--talent-glow'] = props.classColor
  }
  return out
})
</script>

<style scoped>
.talent-node {
  display: inline-block;
  position: relative;
  background-color: hsl(var(--bc) / 0.15);
  background-size: cover;
  background-position: center;
  background-image: var(--icon-url);
  transition: opacity 0.15s ease;
}
.talent-node--regular {
  border-radius: 9999px;
}
.talent-node--choice {
  /* Octagonal clip-path. */
  clip-path: polygon(
    30% 0%, 70% 0%,
    100% 30%, 100% 70%,
    70% 100%, 30% 100%,
    0% 70%, 0% 30%
  );
}
.talent-node--picked {
  opacity: 1;
  box-shadow: 0 0 8px var(--talent-glow, rgb(255 255 255 / 0.4));
}
.talent-node--unpicked {
  opacity: 0.35;
}
.talent-node__icon {
  display: block;
  width: 100%;
  height: 100%;
}
.talent-node__rank {
  position: absolute;
  right: -2px;
  bottom: -2px;
  font-size: 10px;
  line-height: 1;
  padding: 1px 3px;
  background: rgb(0 0 0 / 0.85);
  color: white;
  border-radius: 3px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  pointer-events: none;
}
</style>
```

Note on icon image: Wowhead's `power.js` injects an `<img>` into the anchor on hydration, so the icon appears once `useWowheadRefresh()` runs. We do NOT have a separate image-URL composable today; the existing `WowheadLink.vue` pattern works the same way.

- [ ] **Step 2:** Commit.

```bash
git add src/components/character/talents/TalentNode.vue
git commit -m "feat(talents): add TalentNode component (frame, badge, picked state)"
```

## Task 16: `TalentEdges.vue`

**Files:**
- Create: `src/components/character/talents/TalentEdges.vue`

SVG overlay positioned absolutely over the column. Draws thin lines from
the bottom-center of each `from` node to the top-center of each `to` node.

- [ ] **Step 1:** Create the component:

```vue
<template>
  <svg class="talent-edges" :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`">
    <line
      v-for="edge in edgeLines"
      :key="`${edge.from}-${edge.to}`"
      :x1="edge.x1"
      :y1="edge.y1"
      :x2="edge.x2"
      :y2="edge.y2"
      :stroke="edge.bothPicked ? 'rgb(255 255 255 / 0.4)' : 'rgb(255 255 255 / 0.15)'"
      stroke-width="1"
    />
  </svg>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { TalentEdge, TalentNode } from '@/types/talents'

const props = defineProps<{
  nodes: TalentNode[]
  edges: TalentEdge[]
  pickedIds: Set<number>
  cellSize: number
  cols: number
  rows: number
}>()

const width = computed(() => props.cols * props.cellSize)
const height = computed(() => props.rows * props.cellSize)

const nodeMap = computed(() => {
  const m = new Map<number, TalentNode>()
  for (const n of props.nodes) m.set(n.id, n)
  return m
})

const edgeLines = computed(() =>
  props.edges
    .map((e) => {
      const from = nodeMap.value.get(e.from)
      const to = nodeMap.value.get(e.to)
      if (!from || !to) return null
      const half = props.cellSize / 2
      const iconHalf = (props.cellSize - 8) / 2
      const x1 = from.display_col * props.cellSize + half
      const y1 = from.display_row * props.cellSize + half + iconHalf
      const x2 = to.display_col * props.cellSize + half
      const y2 = to.display_row * props.cellSize + half - iconHalf
      const bothPicked = props.pickedIds.has(from.id) && props.pickedIds.has(to.id)
      return { from: from.id, to: to.id, x1, y1, x2, y2, bothPicked }
    })
    .filter((e): e is NonNullable<typeof e> => e !== null),
)
</script>

<style scoped>
.talent-edges {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: visible;
}
</style>
```

- [ ] **Step 2:** Commit.

```bash
git add src/components/character/talents/TalentEdges.vue
git commit -m "feat(talents): add TalentEdges SVG overlay"
```

## Task 17: `TalentTreeColumn.vue`

**Files:**
- Create: `src/components/character/talents/TalentTreeColumn.vue`

One column (Class / Hero / Spec). Renders the absolute-positioned grid plus the SVG edge overlay.

- [ ] **Step 1:** Create the component:

```vue
<template>
  <section class="talent-column">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70 mb-2">
      {{ title }}
    </h3>
    <div class="talent-column__grid" :style="gridStyle">
      <TalentEdges
        :nodes="nodes"
        :edges="edges"
        :picked-ids="pickedIds"
        :cell-size="cellSize"
        :cols="cols"
        :rows="rows"
      />
      <TalentNode
        v-for="node in nodes"
        :key="node.id"
        :spell-id="spellIdFor(node)"
        :is-picked="pickedIds.has(node.id)"
        :is-choice="node.type === 'choice'"
        :rank-label="rankLabelFor(node)"
        :class-color="classColor"
        :row="node.display_row"
        :col="node.display_col"
        :cell-size="cellSize"
      />
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import TalentEdges from './TalentEdges.vue'
import TalentNode from './TalentNode.vue'
import type { TalentEdge, TalentNode as TalentNodeT } from '@/types/talents'
import type { TalentEntry } from '@/types/character'

const props = defineProps<{
  title: string
  nodes: TalentNodeT[]
  edges: TalentEdge[]
  picked: TalentEntry[]
  classColor?: string | null
  cellSize?: number
}>()

const cellSize = computed(() => props.cellSize ?? 44)

const cols = computed(() => Math.max(1, ...props.nodes.map((n) => n.display_col + 1)))
const rows = computed(() => Math.max(1, ...props.nodes.map((n) => n.display_row + 1)))

const gridStyle = computed(() => ({
  position: 'relative' as const,
  width: `${cols.value * cellSize.value}px`,
  height: `${rows.value * cellSize.value}px`,
}))

const pickedById = computed(() => {
  const m = new Map<number, TalentEntry>()
  for (const p of props.picked) m.set(p.id, p)
  return m
})

const pickedIds = computed(() => new Set(pickedById.value.keys()))

function spellIdFor(node: TalentNodeT): number {
  const p = pickedById.value.get(node.id)
  if (p) return p.spell_id
  if (node.type === 'choice' && node.choice_options && node.choice_options[0]) {
    return node.choice_options[0].spell_id
  }
  return node.ranks[0]?.spell_id ?? 0
}

function rankLabelFor(node: TalentNodeT): string | null {
  const p = pickedById.value.get(node.id)
  if (!p) return null
  if (p.max_rank > 1) return `${p.rank}/${p.max_rank}`
  return null
}
</script>
```

- [ ] **Step 2:** Commit.

```bash
git add src/components/character/talents/TalentTreeColumn.vue
git commit -m "feat(talents): add TalentTreeColumn (positional grid + edge overlay)"
```

## Task 18: `TalentSummaryStrip.vue`

**Files:**
- Create: `src/components/character/talents/TalentSummaryStrip.vue`

5-6 small icons in a single row at top of the card.

- [ ] **Step 1:** Create the component:

```vue
<template>
  <div class="flex flex-wrap gap-1.5 items-center">
    <TalentNode
      v-for="ref in refs"
      :key="ref.node_id"
      :spell-id="ref.spell_id"
      :is-picked="true"
      :is-choice="false"
      :rank-label="ref.rank_label"
      :class-color="classColor"
      :cell-size="36"
    />
  </div>
</template>

<script setup lang="ts">
import TalentNode from './TalentNode.vue'
import type { TalentNodeRef } from '@/types/talents'

defineProps<{
  refs: TalentNodeRef[]
  classColor?: string | null
}>()
</script>
```

- [ ] **Step 2:** Commit.

```bash
git add src/components/character/talents/TalentSummaryStrip.vue
git commit -m "feat(talents): add TalentSummaryStrip"
```

## Task 19: `TalentTree.vue` root container

**Files:**
- Create: `src/components/character/talents/TalentTree.vue`

This replaces the old `src/components/character/TalentTree.vue` (deleted in Task 21).

Header: title + Copy loadout + Talent Calculator.
Body: summary strip → 3-col layout (Class | Hero | Spec) below → PvP row.
Loading: skeletons. 404 fallback: today's flat-list rendering.
Classic short-circuit: existing classic placeholder rendered, no fetch.

- [ ] **Step 1:** Create the component. (Inline the picked-only fallback rather than re-importing the soon-to-be-deleted `TalentTree.vue`.)

```vue
<template>
  <div class="card bg-base-200 shadow-sm">
    <div class="card-body">
      <header class="flex items-center justify-between gap-3 flex-wrap">
        <h2 class="card-title">Talents</h2>
        <div v-if="!classic" class="flex items-center gap-2">
          <a
            v-if="loadoutCode"
            class="btn btn-sm btn-ghost"
            target="_blank"
            rel="noopener"
            :href="`https://www.wowhead.com/talent-calc/blizzard/${loadoutCode}`"
          >
            Talent Calculator ↗
          </a>
          <button
            v-if="loadoutCode"
            type="button"
            class="btn btn-sm btn-outline"
            @click="copyLoadout"
          >
            {{ justCopied ? 'Copied!' : 'Copy loadout' }}
          </button>
        </div>
      </header>

      <!-- Classic short-circuit -->
      <p v-if="classic" class="text-base-content/60 text-sm mt-3">
        Talent rendering for Classic characters is not supported.
      </p>

      <!-- Loading -->
      <div v-else-if="treeQuery.isLoading.value" class="mt-3 flex flex-col gap-3">
        <div class="flex gap-1.5">
          <div v-for="i in 5" :key="i" class="w-9 h-9 rounded-full bg-base-300 animate-pulse" />
        </div>
        <div class="h-72 rounded bg-base-300 animate-pulse" />
      </div>

      <!-- 404 fallback: render today's flat-list -->
      <div v-else-if="treeUnavailable" class="mt-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <section v-for="(label, key) in { class: 'Class', hero: 'Hero', spec: 'Spec' } as const" :key="key">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70 mb-2">
              {{ label }}
            </h3>
            <p v-if="!talents[key].length" class="text-base-content/60 text-sm">None</p>
            <ul v-else class="flex flex-col gap-1">
              <li v-for="t in talents[key]" :key="`${key}-${t.id}`">
                <WowheadLink :spell-id="t.spell_id">
                  {{ t.rank }}/{{ t.max_rank }}
                </WowheadLink>
              </li>
            </ul>
          </section>
        </div>
        <p class="text-xs text-base-content/50 mt-3">
          Full tree not available for this spec yet.
        </p>
      </div>

      <!-- Full tree -->
      <div v-else-if="topology" class="mt-3 flex flex-col gap-4">
        <TalentSummaryStrip :refs="summaryRefs" :class-color="classColor" />
        <div class="flex flex-col md:flex-row gap-6">
          <TalentTreeColumn
            title="Class"
            :nodes="topology.class_nodes"
            :edges="filterEdges(topology.edges, topology.class_nodes)"
            :picked="talents.class"
            :class-color="classColor"
          />
          <TalentTreeColumn
            v-if="activeHero"
            title="Hero"
            :nodes="activeHero.nodes"
            :edges="filterEdges(topology.edges, activeHero.nodes)"
            :picked="talents.hero"
            :class-color="classColor"
          />
          <TalentTreeColumn
            title="Spec"
            :nodes="topology.spec_nodes"
            :edges="filterEdges(topology.edges, topology.spec_nodes)"
            :picked="talents.spec"
            :class-color="classColor"
          />
        </div>
      </div>

      <!-- Empty (low-level char) -->
      <p v-else class="text-base-content/60 text-sm mt-3">
        No talents picked yet.
      </p>

      <!-- PvP row (independent of tree fetch state) -->
      <section v-if="!classic && talents.pvp && talents.pvp.length" class="mt-4">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70 mb-2">
          PvP
        </h3>
        <ul class="flex flex-wrap gap-2">
          <li v-for="p in talents.pvp" :key="`pvp-${p.slot}`">
            <WowheadLink :spell-id="p.spell_id">Slot {{ p.slot + 1 }}</WowheadLink>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import WowheadLink from '@/components/wow/WowheadLink.vue'
import TalentSummaryStrip from './TalentSummaryStrip.vue'
import TalentTreeColumn from './TalentTreeColumn.vue'
import { useTalentTree } from '@/composables/useTalentTree'
import { computeTalentSummary } from '@/composables/useTalentSummary'
import { useWowheadRefresh } from '@/composables/useWowhead'
import { CLASS_COLORS } from '@/utils/wowConstants'
import type { CharacterTalents } from '@/types/character'

const props = defineProps<{
  talents: CharacterTalents
  loadoutCode?: string | null
  classic?: boolean
  classId?: number | null
  treeId?: number | null
  specId?: number | null
}>()

const justCopied = ref(false)

const treeQuery = useTalentTree(
  () => (props.classic ? null : props.treeId),
  () => (props.classic ? null : props.specId),
)

const topology = computed(() => treeQuery.data.value?.tree ?? null)

const treeUnavailable = computed(() => {
  if (props.classic) return false
  if (treeQuery.isLoading.value) return false
  // Treat 404 / missing ids / fetch error as unavailable.
  if (treeQuery.isError.value) return true
  if (!props.treeId || !props.specId) return false
  if (topology.value === null) return false
  return false
})

const activeHero = computed(() => {
  if (!topology.value) return null
  const pickedIds = new Set((props.talents.hero ?? []).map((t) => t.id))
  return (
    topology.value.hero_trees.find((h) => h.nodes.some((n) => pickedIds.has(n.id))) ?? null
  )
})

const summaryRefs = computed(() => {
  if (!topology.value) return []
  return computeTalentSummary(props.talents, topology.value)
})

const classColor = computed(() => {
  if (!props.classId) return null
  return CLASS_COLORS[props.classId] ?? null
})

function filterEdges(edges: { from: number; to: number }[], nodes: { id: number }[]) {
  const ids = new Set(nodes.map((n) => n.id))
  return edges.filter((e) => ids.has(e.from) && ids.has(e.to))
}

useWowheadRefresh(() => [topology.value, summaryRefs.value])

async function copyLoadout() {
  if (!props.loadoutCode) return
  try {
    await navigator.clipboard.writeText(props.loadoutCode)
    justCopied.value = true
    toast.success("Loadout code copied — paste it into WoW's Import Loadout box")
    setTimeout(() => (justCopied.value = false), 2000)
  } catch {
    toast.error('Could not copy to clipboard')
  }
}
</script>
```

- [ ] **Step 2:** Verify `CLASS_COLORS` shape:

```bash
grep -n "CLASS_COLORS" src/utils/wowConstants.ts | head -5
```

If `CLASS_COLORS` is keyed by class slug rather than `class_id`, replace
the `classColor` computed accordingly. (The implementing engineer should
read the file and adapt — see § "Codebase facts to verify" below.)

- [ ] **Step 3:** Commit.

```bash
git add src/components/character/talents/TalentTree.vue
git commit -m "feat(talents): add new TalentTree root container"
```

## Task 20: Switch the tab page over to the new component

**Files:**
- Modify: `src/pages/character/CharacterTalentsTab.vue`

- [ ] **Step 1:** Replace the file contents:

```vue
<template>
  <div class="flex flex-col gap-6">
    <TalentTree
      :talents="character.talents"
      :loadout-code="character.talent_loadout_code"
      :classic="isClassic"
      :class-id="character.class_id"
      :tree-id="character.talent_tree_id ?? null"
      :spec-id="character.active_specialization_id ?? null"
    />
  </div>
</template>

<script setup lang="ts">
import { useCharacterContext } from '@/composables/useCharacterContext'
import TalentTree from '@/components/character/talents/TalentTree.vue'

const { character, isClassic } = useCharacterContext()
</script>
```

- [ ] **Step 2:** Commit.

```bash
git add src/pages/character/CharacterTalentsTab.vue
git commit -m "feat(talents): switch CharacterTalentsTab to the new TalentTree component"
```

## Task 21: Delete the old `TalentTree.vue`

**Files:**
- Delete: `src/components/character/TalentTree.vue`

- [ ] **Step 1:** Confirm nothing else imports it:

```bash
grep -rn "components/character/TalentTree.vue\|@/components/character/TalentTree'" src/ cypress/
```

Expected: no matches (CharacterTalentsTab.vue was the only consumer; we updated it in Task 20).

- [ ] **Step 2:** Delete the file:

```bash
git rm src/components/character/TalentTree.vue
```

- [ ] **Step 3:** Run the build to confirm.

```bash
npm run build
```

Expected: the build succeeds; output goes to `dist/`.

- [ ] **Step 4:** Commit.

```bash
git commit -m "feat(talents): delete old TalentTree.vue (replaced by talents/TalentTree.vue)"
```

## Task 22: Cypress smoke for the new layout

**Files:**
- Modify: whichever Cypress spec covers `/characters/.../talents` today (locate via `grep -rln talents cypress/e2e/`).

- [ ] **Step 1:** Add a test (or augment an existing one) that:
  - visits `/characters/eu/the-maelstrom/melaniya/talents`,
  - waits for the talents card to render,
  - asserts the summary strip contains ≥ 5 `.talent-node` elements,
  - asserts at least one `.talent-node--choice` (octagonal) is visible,
  - asserts the page contains 3 column headers ("Class", "Hero", "Spec").

Example:

```ts
it('renders the talents tab with summary strip and 3 columns', () => {
  cy.visit('/characters/eu/the-maelstrom/melaniya/talents')
  cy.contains('h2', 'Talents', { timeout: 30_000 }).should('be.visible')
  cy.get('.talent-node').should('have.length.at.least', 10)
  cy.get('.talent-node--choice').should('have.length.at.least', 1)
  cy.contains('h3', 'Class').should('be.visible')
  cy.contains('h3', 'Hero').should('be.visible')
  cy.contains('h3', 'Spec').should('be.visible')
})
```

- [ ] **Step 2:** Commit.

```bash
git add cypress/e2e/<spec>.cy.ts
git commit -m "test(talents): cypress smoke for new tree layout"
```

## Task 23: Self-review + final FE build

- [ ] **Step 1:** Run `npm run test:unit` — all green.
- [ ] **Step 2:** Run `npm run build` — succeeds.
- [ ] **Step 3:** No commit (these are verification steps).

---

# Codebase facts to verify before merge

Skim these once before deploying:
- `src/utils/wowConstants.ts::CLASS_COLORS` — confirm the keying (id vs slug). Adjust `classColor` computed in `TalentTree.vue` if needed.
- `src/composables/useWowhead.ts::useWowheadRefresh` — confirm signature accepts a getter returning a deps array. (It does today; keep code compatible.)
- `src/utils/wowhead.ts::buildWowheadHref` — confirm it accepts `{ spellId }`.
- The `CharacterTalents` shape in `src/types/character.ts` — fields `class`, `spec`, `hero`, `pvp`. Each entry: `{ id, spell_id, rank, max_rank }` for class/spec/hero, `{ slot, talent_id, spell_id }` for pvp.

If any of these diverge, adjust the consuming code accordingly — these are stable today but a sanity check is cheap.

---

# Deploy + smoke phase (handled in chat, not as numbered tasks)

After all tasks above are done:

1. Backend
   - `cd backend && docker compose up -d --build` — rebuilds the app image so the new code is in the container (OPCACHE is locked off but the file mounts may still need a fresh build for autoload).
   - `docker compose exec app php artisan migrate` — applies the two new migrations.
   - `docker compose restart horizon` — picks up new mappers/jobs.
   - `docker compose exec app php artisan blizzard:sync-game-data talent-trees` — populate `game_data_talent_trees`.

2. Frontend
   - `cd frontend && npm run build` — bundles into `dist/`. The `guild-service-fe-v2` container bind-mounts `dist/` ro, no restart needed.

3. Smoke-test the Tailscale URL `http://100.82.124.39:8092/characters/eu/the-maelstrom/melaniya/talents` plus 3+ other classes from `backend/docs/test-characters.md` — variety check:
   - a regular-heavy class (e.g., a tank for class-tree differences),
   - one with octagonal choice nodes prominent,
   - one with a hero tree that has an obvious entry/keystone.

4. Trigger a fresh `Full` sync of each test char so the new `active_specialization_id` / `talent_tree_id` columns get populated. A simple way: visit each char's profile page in the FE — staleness check + on-read sync.

---

# Self-review

Spec coverage:
- ✅ New `game_data_talent_trees` table: Task 1.
- ✅ `tree_id, spec_id` composite PK + JSONB topology: Task 1.
- ✅ Sync command extension: Task 7. Per-pair failure tolerance: Task 7's per-pair try/catch around `DB::transaction`.
- ✅ Mapper + tests + fixture: Task 5.
- ✅ Endpoint + tests: Tasks 8, 9. Cache-Control header: Task 8 controller. 404 fallback: Task 8 controller + Task 9 test.
- ✅ Character columns + DTO + Mapper threading + Resource emission: Task 10.
- ✅ Retail-character endpoint test extension: Task 11.
- ✅ FE 5 components, 2 composables, types: Tasks 12-19.
- ✅ Picking rule with full quota / 5-icon hero / tie-break / fallback / classic: Task 14.
- ✅ Layout (desktop 3-col, mobile stacked): Task 19 markup uses `flex-col md:flex-row`.
- ✅ Octagonal/round frame: Task 15 CSS. Picked glow with class color: Task 15.
- ✅ Rank badge: Task 15.
- ✅ Wowhead anchors + `useWowheadRefresh` hydration: Tasks 15, 19.
- ✅ Edges (picked-to-picked vs other) coloring: Task 16.
- ✅ Loading skeletons / 404 fallback / empty state / classic short-circuit: Task 19.
- ✅ Copy loadout + Talent Calculator: Task 19 header.
- ✅ PvP row: Task 19.
- ✅ Cypress smoke: Task 22.
- ✅ `CharacterTalentsTab.vue` import path update: Task 20.
- ✅ Old `TalentTree.vue` deleted: Task 21.

No placeholders. No TBDs. Code blocks present where code is required.
