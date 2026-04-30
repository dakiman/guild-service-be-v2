<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataMountMapper;
use PHPUnit\Framework\TestCase;

class GameDataMountMapperTest extends TestCase
{
    private GameDataMountMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataMountMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 6,
            'name' => 'Onyxian Drake',
            'description' => 'A drake born of Onyxia\'s brood.',
            'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
            'summon_spell' => ['id' => 69395, 'name' => 'Onyxian Drake'],
            'item' => ['id' => 49636, 'name' => 'Reins of the Onyxian Drake'],
        ]);

        $this->assertSame(6, $dto->id);
        $this->assertSame('Onyxian Drake', $dto->name);
        $this->assertSame("A drake born of Onyxia's brood.", $dto->description);
        $this->assertSame('Drop: Onyxia', $dto->sourceText);
        $this->assertSame(69395, $dto->summonSpellId);
        $this->assertSame(49636, $dto->itemId);
    }

    public function test_handles_nested_locale_description(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'description' => ['en_GB' => 'British description'],
        ]);

        $this->assertSame('British description', $dto->description);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Bare Mount',
        ]);

        $this->assertSame(1, $dto->id);
        $this->assertSame('Bare Mount', $dto->name);
        $this->assertNull($dto->description);
        $this->assertNull($dto->sourceText);
        $this->assertNull($dto->summonSpellId);
        $this->assertNull($dto->itemId);
    }

    public function test_source_with_type_only_is_title_cased_without_colon(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'source' => ['type' => 'ACHIEVEMENT'],
        ]);

        $this->assertSame('Achievement', $dto->sourceText);
    }

    public function test_source_with_quest_type_renders_correctly(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'source' => ['type' => 'QUEST', 'name' => 'A Mighty Steed'],
        ]);

        $this->assertSame('Quest: A Mighty Steed', $dto->sourceText);
    }

    public function test_empty_string_description_yields_null(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'description' => '',
        ]);

        $this->assertNull($dto->description);
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
            'mounts' => [
                ['id' => 6, 'name' => 'Onyxian Drake'],
                ['id' => 219, 'name' => 'Tawny Wind Rider'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([6, 219], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
