<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Character;
use App\Models\CharacterRank;
use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterRankRelationTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function rankRow(Character $c, int $seasonId, int $regionRank, array $overrides = []): CharacterRank
    {
        return CharacterRank::create(array_merge([
            'character_id' => $c->id, 'season_id' => $seasonId, 'region' => 'eu',
            'connected_realm_id' => 1403, 'class_id' => 9, 'spec_id' => 267, 'rating' => 2847,
            'world_rank' => 5, 'region_rank' => $regionRank, 'realm_rank' => 1, 'class_rank' => 2, 'spec_rank' => 1,
            'world_pop' => 100, 'region_pop' => 60, 'realm_pop' => 10, 'class_pop' => 20, 'spec_pop' => 8,
            'computed_at' => now(),
        ], $overrides));
    }

    private function currentSeason(int $id): void
    {
        GameDataSeason::query()->update(['is_current' => false]);
        GameDataSeason::updateOrCreate(['id' => $id], [
            'slug' => "season-{$id}", 'name' => "Season {$id}", 'raiderio_tier_slug' => 'tier-x',
            'raiderio_expansion_id' => 11, 'is_current' => true, 'started_at' => '2026-08-22 00:00:00',
        ]);
        Seasons::clearCache();
    }

    public function test_one_row_per_character_and_season_with_cascade_delete(): void
    {
        $character = Character::factory()->create(['region' => 'eu', 'realm' => 'draenor', 'name' => 'ranked']);
        $this->rankRow($character, 17, 9);
        $this->rankRow($character, 18, 3);

        $this->assertSame(2, CharacterRank::where('character_id', $character->id)->count());

        $character->delete();
        $this->assertSame(0, CharacterRank::count());
    }

    public function test_rank_is_the_current_season_row_and_previous_rank_the_newest_older_one(): void
    {
        $character = Character::factory()->create(['region' => 'eu', 'realm' => 'draenor', 'name' => 'ranked']);
        $this->rankRow($character, 15, 40);
        $this->rankRow($character, 17, 9);
        $this->rankRow($character, 18, 3);
        $this->currentSeason(18);

        $fresh = Character::query()->with(['rank', 'previousRank'])->find($character->id);
        $this->assertSame(3, $fresh->rank->region_rank);
        $this->assertSame(18, $fresh->rank->season_id);
        $this->assertSame(9, $fresh->previousRank->region_rank);
        $this->assertSame(17, $fresh->previousRank->season_id);
    }

    public function test_rank_is_null_when_not_ranked_this_season(): void
    {
        $character = Character::factory()->create(['region' => 'eu', 'realm' => 'draenor', 'name' => 'old']);
        $this->rankRow($character, 17, 9);
        $this->currentSeason(18);

        $fresh = Character::query()->with(['rank', 'previousRank'])->find($character->id);
        $this->assertNull($fresh->rank);
        $this->assertSame(17, $fresh->previousRank->season_id);
    }

    public function test_rank_fails_open_without_a_registry_and_previous_rank_is_null(): void
    {
        $character = Character::factory()->create(['region' => 'eu', 'realm' => 'draenor', 'name' => 'noreg']);
        $this->rankRow($character, 18, 3);
        Seasons::clearCache();

        $fresh = Character::query()->with(['rank', 'previousRank'])->find($character->id);
        $this->assertSame(3, $fresh->rank->region_rank);
        $this->assertNull($fresh->previousRank);
    }
}
