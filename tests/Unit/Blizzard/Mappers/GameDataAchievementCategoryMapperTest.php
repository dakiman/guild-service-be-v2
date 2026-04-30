<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataAchievementCategoryMapper;
use PHPUnit\Framework\TestCase;

class GameDataAchievementCategoryMapperTest extends TestCase
{
    private GameDataAchievementCategoryMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataAchievementCategoryMapper();
    }

    public function test_maps_category_with_parent(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 81,
            'name' => 'Quests',
            'parent_category' => ['id' => 1, 'name' => 'General'],
            'display_order' => 3,
        ]);

        $this->assertSame(81, $dto->id);
        $this->assertSame('Quests', $dto->name);
        $this->assertSame(1, $dto->parentId);
        $this->assertSame(3, $dto->displayOrder);
    }

    public function test_maps_root_category_with_null_parent(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'General',
        ]);

        $this->assertNull($dto->parentId);
    }

    public function test_missing_display_order_defaults_to_zero(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'General',
        ]);

        $this->assertSame(0, $dto->displayOrder);
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
            'categories' => [
                ['id' => 1, 'name' => 'General'],
                ['id' => 81, 'name' => 'Quests'],
                ['name' => 'no-id'], // skipped
            ],
        ]);

        $this->assertSame([1, 81], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
