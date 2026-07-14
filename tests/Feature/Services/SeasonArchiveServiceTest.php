<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\DungeonRun;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataSeason;
use App\Models\SeasonArchive;
use App\Services\CharacterStatsService;
use App\Services\SeasonArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeasonArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedSeason(): GameDataSeason
    {
        return GameDataSeason::create([
            'id' => 17,
            'slug' => 'season-mn-1',
            'name' => 'Midnight Season 1',
            'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11,
            'is_current' => true,
        ]);
    }

    public function test_snapshot_contains_only_the_target_season(): void
    {
        $season = $this->seedSeason();
        DungeonRun::factory()->create(['season' => 17, 'dungeon_id' => 503, 'keystone_level' => 20, 'is_completed_on_time' => true]);
        DungeonRun::factory()->create(['season' => 18, 'dungeon_id' => 503, 'keystone_level' => 30, 'is_completed_on_time' => true]);
        GameDataMythicKeystoneDungeon::create([
            'id' => 503,
            'name' => 'Ara-Kara, City of Echoes',
            'media_url' => '/dungeons/503.jpg',
            'keystone_upgrades' => [['upgrade_level' => 1, 'qualifying_duration' => 1800000]],
        ]);
        Cache::forever(CharacterStatsService::CACHE_KEY, [
            'class_distribution' => [['class_id' => 8, 'count' => 3, 'avg_ilvl' => 630, 'avg_mythic_plus_rating' => 2800]],
            'top_performers' => ['mythic_plus' => [['name' => 'melaniya', 'realm' => 'the-maelstrom', 'region' => 'eu', 'class_id' => 8, 'spec_id' => null, 'value' => 3000.0]]],
        ]);

        $archive = app(SeasonArchiveService::class)->snapshot($season);

        $payload = $archive->payload;
        $this->assertSame(17, $payload['meta']['season_id']);
        $this->assertSame('season-mn-1', $payload['meta']['slug']);
        $this->assertSame(1, $payload['meta']['total_runs']);
        $this->assertCount(1, $payload['top_runs']);
        $this->assertSame(20, $payload['top_runs'][0]['keystone_level']);
        $this->assertSame(20, $payload['top_keys']['dungeons'][0]['key_level']);
        $this->assertSame('melaniya', $payload['top_performers']['mythic_plus'][0]['name']);
        $this->assertSame(8, $payload['class_distribution'][0]['class_id']);
        $this->assertSame('/dungeons/503.jpg', $payload['dungeons'][0]['media_url']);
        $this->assertNotEmpty($payload['dungeons'][0]['keystone_upgrades']);
    }

    public function test_refuses_overwrite_without_force(): void
    {
        $season = $this->seedSeason();
        $service = app(SeasonArchiveService::class);
        $service->snapshot($season);

        $this->expectException(\RuntimeException::class);
        $service->snapshot($season);
    }

    public function test_force_overwrites_existing_archive(): void
    {
        $season = $this->seedSeason();
        $service = app(SeasonArchiveService::class);
        $service->snapshot($season);

        DungeonRun::factory()->create(['season' => 17, 'keystone_level' => 25, 'is_completed_on_time' => true]);
        $archive = $service->snapshot($season, force: true);

        $this->assertSame(1, $archive->payload['meta']['total_runs']);
        $this->assertSame(1, SeasonArchive::count());
    }

    public function test_empty_stats_cache_yields_empty_stats_sections(): void
    {
        $season = $this->seedSeason();

        $archive = app(SeasonArchiveService::class)->snapshot($season);

        $this->assertSame([], $archive->payload['top_performers']['mythic_plus']);
        $this->assertSame([], $archive->payload['class_distribution']);
    }
}
