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
        $this->mapper = new GameDataMythicKeystoneDungeonMapper;
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

    public function test_maps_keystone_upgrades_normalized_and_sorted(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 503,
                'name' => 'Ara-Kara, City of Echoes',
                'keystone_upgrades' => [
                    ['upgrade_level' => 3, 'qualifying_duration' => 1080000],
                    ['upgrade_level' => 1, 'qualifying_duration' => 1800000],
                    ['upgrade_level' => 2, 'qualifying_duration' => 1440000],
                ],
            ],
            mediaUrl: null,
            journalInstanceId: null,
        );

        $this->assertSame([
            ['upgrade_level' => 1, 'qualifying_duration' => 1800000],
            ['upgrade_level' => 2, 'qualifying_duration' => 1440000],
            ['upgrade_level' => 3, 'qualifying_duration' => 1080000],
        ], $dto->keystoneUpgrades);
    }

    public function test_absent_empty_or_malformed_keystone_upgrades_map_to_null(): void
    {
        $absent = $this->mapper->mapDetail(['id' => 1, 'name' => 'A'], null, null);
        $this->assertNull($absent->keystoneUpgrades);

        $empty = $this->mapper->mapDetail(
            ['id' => 1, 'name' => 'A', 'keystone_upgrades' => []],
            null,
            null,
        );
        $this->assertNull($empty->keystoneUpgrades);

        $malformed = $this->mapper->mapDetail(
            ['id' => 1, 'name' => 'A', 'keystone_upgrades' => [['upgrade_level' => 1]]],
            null,
            null,
        );
        $this->assertNull($malformed->keystoneUpgrades);

        $scalar = $this->mapper->mapDetail(
            ['id' => 1, 'name' => 'A', 'keystone_upgrades' => 'bogus'],
            null,
            null,
        );
        $this->assertNull($scalar->keystoneUpgrades);
    }
}
