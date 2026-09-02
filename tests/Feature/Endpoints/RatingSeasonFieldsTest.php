<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use App\Models\GameDataSeason;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingSeasonFieldsTest extends TestCase
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

    /** @param array<string, mixed> $attrs */
    private function endgame(string $name, array $attrs): Character
    {
        $now = now();

        return Character::factory()->create(array_merge([
            'name' => $name, 'realm' => 'the-maelstrom', 'region' => 'eu', 'level' => 90,
            'mythic_plus_rating_color' => '#a335ee', 'num_of_searches' => 9, 'last_searched_at' => $now,
            'mythics_synced_at' => $now, 'pvp_synced_at' => $now, 'professions_synced_at' => $now,
            'raids_synced_at' => $now, 'stats_synced_at' => $now, 'titles_synced_at' => $now,
            'reputations_synced_at' => $now, 'collections_synced_at' => $now, 'achievements_synced_at' => $now,
        ], $attrs));
    }

    private const LAST_SEASON = ['season_id' => 17, 'season_slug' => 'season-mn-1', 'season_name' => 'Midnight Season 1', 'is_current' => false];

    private const THIS_SEASON = ['season_id' => 18, 'season_slug' => 'season-mn-2', 'season_name' => 'Midnight Season 2', 'is_current' => true];

    public function test_character_rating_block_names_its_season(): void
    {
        $this->endgame('melaniya', ['mythic_plus_rating' => 2723, 'rating_season_id' => 17]);
        $this->endgame('fresh', ['mythic_plus_rating' => 2900, 'rating_season_id' => 18]);
        $this->endgame('untagged', ['mythic_plus_rating' => 1500, 'rating_season_id' => null]);

        $this->getJson('/api/v1/characters/eu/the-maelstrom/melaniya')->assertOk()
            ->assertJsonPath('data.mythic_plus_rating.rating', 2723)
            ->assertJsonPath('data.mythic_plus_rating.season_id', 17)
            ->assertJsonPath('data.mythic_plus_rating.season_slug', 'season-mn-1')
            ->assertJsonPath('data.mythic_plus_rating.season_name', 'Midnight Season 1')
            ->assertJsonPath('data.mythic_plus_rating.is_current', false)
            ->assertJsonPath('data.previous_rank', null);
        $this->getJson('/api/v1/characters/eu/the-maelstrom/fresh')->assertOk()
            ->assertJsonPath('data.mythic_plus_rating.is_current', true)
            ->assertJsonPath('data.mythic_plus_rating.season_slug', 'season-mn-2');
        $this->getJson('/api/v1/characters/eu/the-maelstrom/untagged')->assertOk()
            ->assertJsonPath('data.mythic_plus_rating.season_id', null)
            ->assertJsonPath('data.mythic_plus_rating.season_slug', null)
            ->assertJsonPath('data.mythic_plus_rating.is_current', false);
    }

    public function test_suggest_and_popular_carry_the_season_fields(): void
    {
        $this->endgame('melaniya', ['mythic_plus_rating' => 2723, 'rating_season_id' => 17]);

        $suggest = collect($this->getJson('/api/v1/characters/suggest?q=mel')->assertOk()->json('suggestions'))->keyBy('name');
        $this->assertSame(
            ['rating' => 2723, 'color' => '#a335ee'] + self::LAST_SEASON,
            $suggest['melaniya']['mythic_plus_rating'],
        );

        $popular = $this->getJson('/api/v1/characters/popular')->assertOk();
        $this->assertSame('season-mn-1', $popular->json('most_popular.0.mythic_plus_rating.season_slug'));
        $this->assertFalse($popular->json('recently_searched.0.mythic_plus_rating.is_current'));
    }

    public function test_guild_roster_rows_carry_the_season_fields(): void
    {
        $c = $this->endgame('melaniya', ['mythic_plus_rating' => 2900, 'rating_season_id' => 18]);
        $guild = Guild::factory()->create([
            'region' => 'eu', 'realm' => 'the-maelstrom', 'name' => 'balkanika', 'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'character_id' => $c->id, 'name' => $c->name, 'realm' => $c->realm,
            'level' => 90, 'class_id' => $c->class_id, 'race_id' => 1, 'rank' => 0,
        ]);

        $res = $this->getJson('/api/v1/guilds/eu/the-maelstrom/balkanika')->assertOk();

        $this->assertSame(
            ['rating' => 2900, 'color' => '#a335ee'] + self::THIS_SEASON,
            $res->json('members.data.0.mythic_plus_rating'),
        );
    }
}
