<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataFactionMapper;
use PHPUnit\Framework\TestCase;

class GameDataFactionMapperTest extends TestCase
{
    private GameDataFactionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataFactionMapper();
    }

    public function test_maps_a_known_TWW_faction_to_expansion_1(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 2570,
            'name' => 'Council of Dornogal',
            'category' => ['id' => 1245],
        ]);

        $this->assertSame(2570, $dto->id);
        $this->assertSame('Council of Dornogal', $dto->name);
        $this->assertSame(1245, $dto->parentFactionId);
        $this->assertSame(1, $dto->expansionId);
    }

    public function test_maps_a_known_dragonflight_faction_to_expansion_2(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 2510,
            'name' => 'Valdrakken Accord',
        ]);

        $this->assertSame(2, $dto->expansionId);
    }

    public function test_unknown_faction_id_yields_null_expansion_id(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 99999,
            'name' => 'Future Faction',
        ]);

        $this->assertNull($dto->expansionId);
    }

    public function test_missing_category_yields_null_parent(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 2570,
            'name' => 'Council of Dornogal',
        ]);

        $this->assertNull($dto->parentFactionId);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID']));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'factions' => [
                ['id' => 100, 'name' => 'A'],
                ['id' => 200, 'name' => 'B'],
                ['name' => 'C-no-id'],
            ],
        ]);

        $this->assertSame([100, 200], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
