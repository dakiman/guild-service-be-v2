<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataAchievementMapper;
use PHPUnit\Framework\TestCase;

class GameDataAchievementMapperTest extends TestCase
{
    private GameDataAchievementMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataAchievementMapper();
    }

    public function test_maps_full_achievement(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 230,
            'name' => 'Hatchling of the Talon',
            'description' => 'Obtain 50 mounts.',
            'category' => ['id' => 15246, 'name' => 'Mounts'],
            'points' => 10,
            'is_account_wide' => true,
        ]);

        $this->assertSame(230, $dto->id);
        $this->assertSame('Hatchling of the Talon', $dto->name);
        $this->assertSame('Obtain 50 mounts.', $dto->description);
        $this->assertSame(15246, $dto->categoryId);
        $this->assertSame(10, $dto->points);
        $this->assertTrue($dto->isAccountWide);
    }

    public function test_missing_is_account_wide_defaults_to_false(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Old Achievement',
            'category' => ['id' => 1],
        ]);

        $this->assertFalse($dto->isAccountWide);
    }

    public function test_missing_optional_fields_yield_defaults(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Bare Achievement',
        ]);

        $this->assertNull($dto->description);
        $this->assertNull($dto->categoryId);
        $this->assertSame(0, $dto->points);
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
            'achievements' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 230, 'name' => 'B'],
                ['name' => 'no-id'], // skipped
            ],
        ]);

        $this->assertSame([1, 230], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
