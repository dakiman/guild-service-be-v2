<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataKeystoneAffixMapper;
use PHPUnit\Framework\TestCase;

class GameDataKeystoneAffixMapperTest extends TestCase
{
    private GameDataKeystoneAffixMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataKeystoneAffixMapper;
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 9,
                'name' => 'Tyrannical',
                'description' => 'Bosses have 30% more health.',
            ],
            iconUrl: 'https://render.worldofwarcraft.com/eu/icons/affix-9.jpg',
        );

        $this->assertSame(9, $dto->id);
        $this->assertSame('Tyrannical', $dto->name);
        $this->assertSame('https://render.worldofwarcraft.com/eu/icons/affix-9.jpg', $dto->iconUrl);
    }

    public function test_handles_missing_icon(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: ['id' => 10, 'name' => 'Fortified'],
            iconUrl: null,
        );

        $this->assertNull($dto->iconUrl);
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
            'affixes' => [
                ['id' => 9, 'name' => 'Tyrannical'],
                ['id' => 10, 'name' => 'Fortified'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([9, 10], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }

    public function test_extract_icon_url_from_media_response(): void
    {
        $url = $this->mapper->extractIconUrl([
            'assets' => [
                ['key' => 'icon', 'value' => 'https://example/affix-9.jpg'],
            ],
        ]);

        $this->assertSame('https://example/affix-9.jpg', $url);
        $this->assertNull($this->mapper->extractIconUrl(null));
    }
}
