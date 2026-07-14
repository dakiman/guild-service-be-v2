<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataSeason;
use App\Models\SeasonArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function seedSeasons(): void
    {
        GameDataSeason::create([
            'id' => 17,
            'slug' => 'season-mn-1',
            'name' => 'Midnight Season 1',
            'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11,
            'is_current' => false,
            'started_at' => '2026-03-18 00:00:00',
            'ended_at' => '2026-12-16 00:00:00',
        ]);
        GameDataSeason::create([
            'id' => 18,
            'slug' => 'season-mn-2',
            'name' => 'Midnight Season 2',
            'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11,
            'is_current' => true,
        ]);
        SeasonArchive::create([
            'season_id' => 17,
            'payload' => ['meta' => ['slug' => 'season-mn-1'], 'top_runs' => []],
            'snapshotted_at' => now(),
        ]);
    }

    public function test_seasons_list_newest_first_with_archive_flags(): void
    {
        $this->seedSeasons();

        $response = $this->getJson('/api/v1/game-data/seasons');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertJsonPath('seasons.0.id', 18)
            ->assertJsonPath('seasons.0.is_current', true)
            ->assertJsonPath('seasons.0.has_archive', false)
            ->assertJsonPath('seasons.1.id', 17)
            ->assertJsonPath('seasons.1.is_current', false)
            ->assertJsonPath('seasons.1.has_archive', true);
    }

    public function test_seasons_list_empty_registry(): void
    {
        $response = $this->getJson('/api/v1/game-data/seasons');

        $response->assertOk()->assertExactJson(['seasons' => []]);
    }

    public function test_archive_payload_served_by_slug(): void
    {
        $this->seedSeasons();

        $response = $this->getJson('/api/v1/stats/archive/seasons/season-mn-1');

        $response->assertOk()
            ->assertJsonPath('meta.slug', 'season-mn-1');
    }

    public function test_archive_404_for_unarchived_or_unknown_slug(): void
    {
        $this->seedSeasons();

        // Known season, no archive row.
        $this->getJson('/api/v1/stats/archive/seasons/season-mn-2')->assertNotFound();
        // Unknown slug entirely.
        $this->getJson('/api/v1/stats/archive/seasons/season-nope')->assertNotFound();
    }
}
