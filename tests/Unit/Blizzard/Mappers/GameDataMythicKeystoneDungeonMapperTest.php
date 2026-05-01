<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper;
use PHPUnit\Framework\TestCase;

class GameDataMythicKeystoneDungeonMapperTest extends TestCase
{
    private GameDataMythicKeystoneDungeonMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataMythicKeystoneDungeonMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 503,
                'name' => 'Ara-Kara, City of Echoes',
                'map' => ['id' => 2293],
                'keystone_upgrades' => [],
            ],
            mediaUrl: 'https://example/arak.jpg',
            journalInstanceId: 1271,
        );

        $this->assertSame(503, $dto->id);
        $this->assertSame('Ara-Kara, City of Echoes', $dto->name);
        $this->assertSame('https://example/arak.jpg', $dto->mediaUrl);
        $this->assertSame(1271, $dto->journalInstanceId);
    }

    public function test_handles_missing_media_and_journal_instance(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: ['id' => 1, 'name' => 'Bare'],
            mediaUrl: null,
            journalInstanceId: null,
        );

        $this->assertNull($dto->mediaUrl);
        $this->assertNull($dto->journalInstanceId);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null, null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null, null));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'dungeons' => [
                ['id' => 503, 'name' => 'A'],
                ['id' => 504, 'name' => 'B'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([503, 504], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }

    public function test_extract_media_url(): void
    {
        $this->assertSame(
            'https://example/d.png',
            $this->mapper->extractMediaUrl([
                'assets' => [['key' => 'tile', 'value' => 'https://example/d.png']],
            ]),
        );
        $this->assertNull($this->mapper->extractMediaUrl(null));
    }
}
