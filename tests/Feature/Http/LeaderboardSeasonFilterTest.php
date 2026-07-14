<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\DungeonRun;
use App\Models\GameDataSeason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardSeasonFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrentSeason(int $id): void
    {
        GameDataSeason::create([
            'id' => $id,
            'slug' => "season-test-{$id}",
            'name' => "Test Season {$id}",
            'raiderio_tier_slug' => 'tier-test',
            'raiderio_expansion_id' => 11,
            'is_current' => true,
        ]);
    }

    public function test_top_runs_only_returns_current_season(): void
    {
        $this->seedCurrentSeason(18);
        // Old-season monster key that would dominate an unfiltered board.
        DungeonRun::factory()->create(['season' => 17, 'keystone_level' => 30, 'is_completed_on_time' => true]);
        DungeonRun::factory()->create(['season' => 18, 'keystone_level' => 10, 'is_completed_on_time' => true]);

        $response = $this->getJson('/api/v1/stats/characters/top-runs');

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertSame(10, $response->json('data.0.keystone_level'));
    }

    public function test_top_keys_only_returns_current_season(): void
    {
        $this->seedCurrentSeason(18);
        DungeonRun::factory()->create(['season' => 17, 'dungeon_id' => 503, 'keystone_level' => 30, 'is_completed_on_time' => true]);
        DungeonRun::factory()->create(['season' => 18, 'dungeon_id' => 503, 'keystone_level' => 12, 'is_completed_on_time' => true]);

        $response = $this->getJson('/api/v1/stats/characters/top-keys');

        $response->assertOk();
        $this->assertCount(1, $response->json('dungeons'));
        $this->assertSame(12, $response->json('dungeons.0.key_level'));
    }

    public function test_empty_registry_fails_open_to_all_seasons(): void
    {
        DungeonRun::factory()->create(['season' => 17, 'keystone_level' => 30, 'is_completed_on_time' => true]);
        DungeonRun::factory()->create(['season' => 18, 'keystone_level' => 10, 'is_completed_on_time' => true]);

        $response = $this->getJson('/api/v1/stats/characters/top-runs');

        $response->assertOk();
        $this->assertSame(2, $response->json('total'));
    }
}
