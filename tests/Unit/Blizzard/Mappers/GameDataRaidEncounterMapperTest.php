<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataRaidEncounterMapper;
use PHPUnit\Framework\TestCase;

class GameDataRaidEncounterMapperTest extends TestCase
{
    private GameDataRaidEncounterMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataRaidEncounterMapper;
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 2902,
                'name' => 'Vexie and the Geargrinders',
                'instance' => ['id' => 1296],
                'creature_display' => ['id' => 109501],
                'order_index' => 0,
            ],
            portraitUrl: 'https://render.worldofwarcraft.com/eu/npcs/zoom/creature-display-109501.jpg',
            fallbackInstanceId: 1296,
            fallbackOrder: 0,
        );

        $this->assertSame(2902, $dto->id);
        $this->assertSame(1296, $dto->raidInstanceId);
        $this->assertSame('Vexie and the Geargrinders', $dto->name);
        $this->assertSame(0, $dto->displayOrder);
        $this->assertSame(109501, $dto->creatureDisplayId);
        $this->assertSame('https://render.worldofwarcraft.com/eu/npcs/zoom/creature-display-109501.jpg', $dto->portraitUrl);
    }

    public function test_falls_back_when_instance_and_order_are_missing(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 2917,
                'name' => 'Cauldron of Carnage',
            ],
            portraitUrl: null,
            fallbackInstanceId: 1296,
            fallbackOrder: 4,
        );

        $this->assertSame(1296, $dto->raidInstanceId, 'falls back to the parent instance id passed in');
        $this->assertSame(4, $dto->displayOrder, 'falls back to the supplied order');
        $this->assertNull($dto->creatureDisplayId);
        $this->assertNull($dto->portraitUrl);
    }

    public function test_extracts_creature_display_id_from_creature_displays_array(): void
    {
        // Some Blizzard responses use an array `creature_displays` instead of
        // singular `creature_display`. Mapper should accept either; first
        // entry wins.
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 1,
                'name' => 'X',
                'creature_displays' => [
                    ['id' => 200001],
                    ['id' => 200002],
                ],
            ],
            portraitUrl: null,
            fallbackInstanceId: 0,
            fallbackOrder: 0,
        );

        $this->assertSame(200001, $dto->creatureDisplayId);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null, 0, 0));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null, 0, 0));
    }

    public function test_extract_media_url_returns_zoom_asset(): void
    {
        $url = $this->mapper->extractMediaUrl([
            'assets' => [
                ['key' => 'zoom', 'value' => 'https://example/zoom.jpg'],
            ],
        ]);

        $this->assertSame('https://example/zoom.jpg', $url);
    }

    public function test_extract_media_url_returns_null_when_assets_missing(): void
    {
        $this->assertNull($this->mapper->extractMediaUrl(null));
        $this->assertNull($this->mapper->extractMediaUrl(['assets' => []]));
    }
}
