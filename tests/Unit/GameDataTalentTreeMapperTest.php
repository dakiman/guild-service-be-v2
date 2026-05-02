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
