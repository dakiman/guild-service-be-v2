<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use App\Models\CharacterRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterRankBlockTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertJsonPath('data.rank.percentile', 4.0)
            ->assertJsonPath('data.rank.connected_realm_id', 1090)
            ->assertJsonPath('data.rank.season_id', 18);
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
}
