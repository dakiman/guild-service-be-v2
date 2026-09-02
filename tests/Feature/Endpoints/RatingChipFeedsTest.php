<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use App\Models\CharacterRank;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingChipFeedsTest extends TestCase
{
    use RefreshDatabase;

    private function rankedCharacter(): Character
    {
        $c = Character::factory()->create([
            'name' => 'melaniya', 'realm' => 'the-maelstrom', 'region' => 'eu', 'level' => 90,
            'mythic_plus_rating' => 2847, 'mythic_plus_rating_color' => '#ff8000', 'num_of_searches' => 9,
            'last_searched_at' => now(),
        ]);
        CharacterRank::create([
            'character_id' => $c->id, 'season_id' => 18, 'region' => 'eu', 'connected_realm_id' => 1090,
            'class_id' => $c->class_id, 'spec_id' => 267, 'rating' => 2847,
            'world_rank' => 10, 'region_rank' => 4, 'realm_rank' => 1, 'class_rank' => 2, 'spec_rank' => 1,
            'world_pop' => 1, 'region_pop' => 1, 'realm_pop' => 1, 'class_pop' => 1, 'spec_pop' => 1,
            'computed_at' => now(),
        ]);

        return $c;
    }

    public function test_suggest_carries_rating_and_region_rank(): void
    {
        $this->rankedCharacter();
        Character::factory()->create(['name' => 'melvin', 'region' => 'eu', 'realm' => 'draenor', 'mythic_plus_rating' => null]);

        $res = $this->getJson('/api/v1/characters/suggest?q=mel')->assertOk();

        $rows = collect($res->json('suggestions'))->keyBy('name');
        $this->assertSame(['rating' => 2847, 'color' => '#ff8000'], $rows['melaniya']['mythic_plus_rating']);
        $this->assertSame(4, $rows['melaniya']['region_rank']);
        $this->assertNull($rows['melvin']['mythic_plus_rating']);
        $this->assertNull($rows['melvin']['region_rank']);
    }

    public function test_popular_carries_rating_and_region_rank(): void
    {
        $this->rankedCharacter();

        $res = $this->getJson('/api/v1/characters/popular')->assertOk();

        $this->assertSame(4, $res->json('most_popular.0.region_rank'));
        $this->assertSame(2847, $res->json('recently_searched.0.mythic_plus_rating.rating'));
    }

    public function test_guild_roster_rows_carry_region_rank(): void
    {
        $c = $this->rankedCharacter();
        $guild = Guild::factory()->create([
            'region' => 'eu', 'realm' => 'the-maelstrom', 'name' => 'balkanika', 'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'character_id' => $c->id, 'name' => $c->name, 'realm' => $c->realm,
            'level' => 90, 'class_id' => $c->class_id, 'race_id' => 1, 'rank' => 0,
        ]);

        $res = $this->getJson('/api/v1/guilds/eu/the-maelstrom/balkanika')->assertOk();

        $this->assertSame(4, $res->json('members.data.0.region_rank'));
        $this->assertSame(2847, $res->json('members.data.0.mythic_plus_rating.rating'));
    }
}
