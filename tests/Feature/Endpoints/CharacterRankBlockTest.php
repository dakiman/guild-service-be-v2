<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use App\Models\CharacterRank;
use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterRankBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        GameDataSeason::create([
            'id' => 17, 'slug' => 'season-mn-1', 'name' => 'Midnight Season 1', 'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11, 'is_current' => false, 'started_at' => '2026-03-18 00:00:00', 'ended_at' => '2026-08-22 00:00:00',
        ]);
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'Midnight Season 2', 'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11, 'is_current' => true, 'started_at' => '2026-08-22 00:00:00',
        ]);
        Seasons::clearCache();
    }

    public function test_ranked_character_exposes_rank_block_with_region_percentile(): void
    {
        $now = now();
        $c = Character::factory()->create([
            'name' => 'melaniya', 'realm' => 'the-maelstrom', 'region' => 'eu', 'level' => 90,
            'mythic_plus_rating' => 2847, 'mythic_plus_rating_color' => '#ff8000',
            'mythics_synced_at' => $now, 'pvp_synced_at' => $now, 'professions_synced_at' => $now,
            'raids_synced_at' => $now, 'stats_synced_at' => $now, 'titles_synced_at' => $now,
            'reputations_synced_at' => $now, 'collections_synced_at' => $now, 'achievements_synced_at' => $now,
        ]);
        CharacterRank::create([
            'character_id' => $c->id, 'season_id' => 18, 'region' => 'eu', 'connected_realm_id' => 1090,
            'class_id' => 9, 'spec_id' => 267, 'rating' => 2847,
            'world_rank' => 18940, 'region_rank' => 3292, 'realm_rank' => 312, 'class_rank' => 1402, 'spec_rank' => 640,
            'world_pop' => 152560, 'region_pop' => 82297, 'realm_pop' => 1900, 'class_pop' => 14000, 'spec_pop' => 6100,
            'computed_at' => '2026-09-01 04:03:11',
        ]);

        $res = $this->getJson('/api/v1/characters/eu/the-maelstrom/melaniya');

        $res->assertOk()->assertJsonPath('data.rank.region', 3292)
            ->assertJsonPath('data.rank.realm', 312)
            ->assertJsonPath('data.rank.population.region', 82297)
            ->assertJsonPath('data.rank.connected_realm_id', 1090)
            ->assertJsonPath('data.rank.season_id', 18)
            ->assertJsonPath('data.rank.season_slug', 'season-mn-2')
            ->assertJsonPath('data.rank.is_current', true)
            ->assertJsonPath('data.previous_rank', null);
        $this->assertEqualsWithDelta(4.0, $res->json('data.rank.percentile'), 0.01);
        $this->assertStringStartsWith('2026-09-01T04:03:11', $res->json('data.rank.computed_at'));
    }

    public function test_unranked_character_has_null_rank(): void
    {
        $now = now();
        Character::factory()->create([
            'name' => 'nobody', 'realm' => 'draenor', 'region' => 'eu', 'level' => 90, 'mythic_plus_rating' => 1200,
            'mythics_synced_at' => $now, 'pvp_synced_at' => $now, 'professions_synced_at' => $now,
            'raids_synced_at' => $now, 'stats_synced_at' => $now, 'titles_synced_at' => $now,
            'reputations_synced_at' => $now, 'collections_synced_at' => $now, 'achievements_synced_at' => $now,
        ]);

        $this->getJson('/api/v1/characters/eu/draenor/nobody')->assertOk()->assertJsonPath('data.rank', null);
    }

    public function test_last_season_row_is_previous_rank_not_rank(): void
    {
        $now = now();
        $c = Character::factory()->create([
            'name' => 'veteran', 'realm' => 'draenor', 'region' => 'eu', 'level' => 90, 'mythic_plus_rating' => 2600,
            'rating_season_id' => 17,
            'mythics_synced_at' => $now, 'pvp_synced_at' => $now, 'professions_synced_at' => $now,
            'raids_synced_at' => $now, 'stats_synced_at' => $now, 'titles_synced_at' => $now,
            'reputations_synced_at' => $now, 'collections_synced_at' => $now, 'achievements_synced_at' => $now,
        ]);
        CharacterRank::create([
            'character_id' => $c->id, 'season_id' => 17, 'region' => 'eu', 'connected_realm_id' => 1403,
            'class_id' => 9, 'spec_id' => 267, 'rating' => 2600,
            'world_rank' => 100, 'region_rank' => 40, 'realm_rank' => 2, 'class_rank' => 9, 'spec_rank' => 4,
            'world_pop' => 1000, 'region_pop' => 400, 'realm_pop' => 20, 'class_pop' => 90, 'spec_pop' => 40,
            'computed_at' => '2026-08-21 04:00:00',
        ]);

        $this->getJson('/api/v1/characters/eu/draenor/veteran')->assertOk()
            ->assertJsonPath('data.rank', null)
            ->assertJsonPath('data.previous_rank.season_id', 17)
            ->assertJsonPath('data.previous_rank.season_slug', 'season-mn-1')
            ->assertJsonPath('data.previous_rank.season_name', 'Midnight Season 1')
            ->assertJsonPath('data.previous_rank.is_current', false)
            ->assertJsonPath('data.previous_rank.region', 40)
            ->assertJsonPath('data.previous_rank.percentile', 10);
    }
}
