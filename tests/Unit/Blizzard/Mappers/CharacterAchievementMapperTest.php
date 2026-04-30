<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterAchievementMapper;
use Tests\TestCase;

class CharacterAchievementMapperTest extends TestCase
{
    public function test_returns_empty_for_null(): void
    {
        $this->assertSame([], (new CharacterAchievementMapper)->map(null));
    }

    public function test_returns_empty_for_missing_achievements_key(): void
    {
        $this->assertSame([], (new CharacterAchievementMapper)->map(['total_quantity' => 0]));
    }

    public function test_maps_top_level_achievements(): void
    {
        $payload = [
            'achievements' => [
                ['id' => 100, 'completed_timestamp' => 1700000000000],
                ['id' => 200, 'completed_timestamp' => 1700000001000],
                ['id' => 300],
            ],
        ];

        $out = (new CharacterAchievementMapper)->map($payload);

        $this->assertCount(3, $out);
        $this->assertSame(100, $out[0]->achievementId);
        $this->assertSame(1700000000000, $out[0]->completedTimestamp);
        $this->assertSame(300, $out[2]->achievementId);
        $this->assertNull($out[2]->completedTimestamp);
    }

    public function test_dedupes_repeated_ids(): void
    {
        $payload = [
            'achievements' => [
                ['id' => 100, 'completed_timestamp' => 1],
                ['id' => 100, 'completed_timestamp' => 2],
            ],
        ];

        $this->assertCount(1, (new CharacterAchievementMapper)->map($payload));
    }

    public function test_skips_zero_id(): void
    {
        $this->assertSame([], (new CharacterAchievementMapper)->map([
            'achievements' => [['id' => 0, 'completed_timestamp' => 1]],
        ]));
    }
}
