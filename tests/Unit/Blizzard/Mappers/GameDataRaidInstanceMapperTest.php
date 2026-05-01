<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataRaidInstanceMapper;
use PHPUnit\Framework\TestCase;

class GameDataRaidInstanceMapperTest extends TestCase
{
    private GameDataRaidInstanceMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataRaidInstanceMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 1296,
                'name' => 'Liberation of Undermine',
                'expansion' => ['id' => 514], // Blizzard's journal-expansion id for TWW → maps to our id 1
                'order_index' => 5,
                'encounters' => [
                    ['id' => 2902, 'name' => 'Vexie'],
                    ['id' => 2917, 'name' => 'Cauldron of Carnage'],
                ],
            ],
            mediaUrl: 'https://render.worldofwarcraft.com/eu/icons/lou.jpg',
        );

        $this->assertSame(1296, $dto->id);
        $this->assertSame('Liberation of Undermine', $dto->name);
        $this->assertSame(1, $dto->expansionId);
        $this->assertSame(5, $dto->displayOrder);
        $this->assertSame('https://render.worldofwarcraft.com/eu/icons/lou.jpg', $dto->mediaUrl);
        $this->assertSame([2902, 2917], $dto->encounterIds);
    }

    public function test_unmapped_blizzard_expansion_id_falls_back_to_null(): void
    {
        // Blizzard IDs like 505 ("Current Season") and 516 ("Midnight") have no
        // entry in our seeded game_data_expansions table; the FK accepts null.
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 9999,
                'name' => 'Unknown Tier',
                'expansion' => ['id' => 505],
            ],
            mediaUrl: null,
        );

        $this->assertNotNull($dto);
        $this->assertNull($dto->expansionId);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: ['id' => 9, 'name' => 'Bare Raid'],
            mediaUrl: null,
        );

        $this->assertSame(9, $dto->id);
        $this->assertNull($dto->expansionId);
        $this->assertSame(0, $dto->displayOrder);
        $this->assertNull($dto->mediaUrl);
        $this->assertSame([], $dto->encounterIds);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'instances' => [
                ['id' => 1296, 'name' => 'A'],
                ['id' => 1273, 'name' => 'B'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([1296, 1273], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }

    public function test_extract_media_url_from_media_response(): void
    {
        $url = $this->mapper->extractMediaUrl([
            'assets' => [
                ['key' => 'tile', 'value' => 'https://example/raid.jpg'],
            ],
        ]);

        $this->assertSame('https://example/raid.jpg', $url);
    }

    public function test_extract_media_url_returns_null_when_no_assets(): void
    {
        $this->assertNull($this->mapper->extractMediaUrl(['assets' => []]));
        $this->assertNull($this->mapper->extractMediaUrl(null));
    }
}
