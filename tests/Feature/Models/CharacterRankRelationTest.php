<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Character;
use App\Models\CharacterRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterRankRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_has_one_rank_row_and_cascade_deletes(): void
    {
        $character = Character::factory()->create(['region' => 'eu', 'realm' => 'draenor', 'name' => 'ranked']);

        CharacterRank::create([
            'character_id' => $character->id, 'season_id' => 18, 'region' => 'eu',
            'connected_realm_id' => 1403, 'class_id' => 9, 'spec_id' => 267, 'rating' => 2847,
            'world_rank' => 5, 'region_rank' => 3, 'realm_rank' => 1, 'class_rank' => 2, 'spec_rank' => 1,
            'world_pop' => 100, 'region_pop' => 60, 'realm_pop' => 10, 'class_pop' => 20, 'spec_pop' => 8,
            'computed_at' => now(),
        ]);

        $this->assertSame(3, $character->fresh()->rank->region_rank);
        $this->assertSame(1403, $character->fresh()->rank->connected_realm_id);

        $character->delete();
        $this->assertSame(0, CharacterRank::count());
    }
}
