<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\CharacterRank;
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MaterializeRanksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'MN Season 2', 'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11, 'is_current' => true, 'started_at' => '2026-08-22 00:00:00',
        ]);
        Seasons::clearCache();
        GameDataConnectedRealm::create(['region' => 'eu', 'connected_realm_id' => 1403, 'realm_slugs' => ['draenor']]);
        GameDataConnectedRealm::create(['region' => 'eu', 'connected_realm_id' => 1084, 'realm_slugs' => ['tarren-mill', 'dentarg']]);
        GameDataConnectedRealm::create(['region' => 'us', 'connected_realm_id' => 57, 'realm_slugs' => ['illidan']]);
    }

    /** @param array<string, mixed> $overrides */
    private function ranked(string $name, int $rating, array $overrides = []): Character
    {
        return Character::factory()->create(array_merge([
            'name' => $name, 'region' => 'eu', 'realm' => 'draenor', 'level' => 90, 'class_id' => 9,
            'active_specialization_id' => 267, 'mythic_plus_rating' => $rating,
            'rating_season_id' => 18, 'rating_synced_at' => '2026-08-25 12:00:00',
        ], $overrides));
    }

    private function rankOf(Character $c): CharacterRank
    {
        return CharacterRank::query()->where('character_id', $c->id)->where('season_id', 18)->firstOrFail();
    }

    public function test_ranks_every_scope_with_competition_ranking_and_populations(): void
    {
        $a = $this->ranked('alpha', 3000);
        $b = $this->ranked('bravo', 2500, ['realm' => 'tarren-mill', 'class_id' => 12, 'active_specialization_id' => 581]);
        $c = $this->ranked('charlie', 2500, ['realm' => 'dentarg']);
        $d = $this->ranked('delta', 2000, ['region' => 'us', 'realm' => 'illidan']);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertSame(4, CharacterRank::count());

        $ra = $this->rankOf($a);
        $this->assertSame([1, 1, 1, 1, 1], [$ra->world_rank, $ra->region_rank, $ra->realm_rank, $ra->class_rank, $ra->spec_rank]);
        $this->assertSame([4, 3, 1, 2, 2], [$ra->world_pop, $ra->region_pop, $ra->realm_pop, $ra->class_pop, $ra->spec_pop]);
        $this->assertSame(1403, $ra->connected_realm_id);
        $this->assertSame(18, $ra->season_id);

        // Tie at 2500: both rank 2 in world/region; delta (2000) is world rank 4 (competition ranking skips 3).
        $this->assertSame(2, $this->rankOf($b)->world_rank);
        $this->assertSame(2, $this->rankOf($c)->world_rank);
        $this->assertSame(4, $this->rankOf($d)->world_rank);
        $this->assertSame(1, $this->rankOf($d)->region_rank);

        // bravo + charlie share connected realm 1084 → realm pop 2, both realm rank 1.
        $rb = $this->rankOf($b);
        $this->assertSame(1084, $rb->connected_realm_id);
        $this->assertSame(2, $rb->realm_pop);
        $this->assertSame(1, $rb->realm_rank);
        $this->assertSame(1, $rb->class_rank);   // only DH in EU
        $this->assertSame(1, $rb->spec_rank);

        $this->assertNotNull(Cache::get('ranks:computed_at'));
    }

    public function test_population_gate_is_the_season_tag_not_the_sync_stamp(): void
    {
        $this->ranked('fresh', 2000);
        $this->ranked('oldstamp', 2100, ['rating_synced_at' => '2026-08-01 00:00:00']); // tagged 18 → still in
        $this->ranked('lastseason', 2900, ['rating_season_id' => 17]);
        $this->ranked('untagged', 2900, ['rating_season_id' => null]);
        $this->ranked('unrated', 0);
        $this->ranked('lowbie', 2900, ['level' => 80]);
        $this->ranked('classic', 2900, ['game_version' => 'classic']);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertSame(
            ['oldstamp', 'fresh'],
            CharacterRank::query()->with('character')->orderBy('world_rank')->get()->pluck('character.name')->all(),
        );
    }

    public function test_unmapped_realm_gets_null_realm_rank_but_other_ranks(): void
    {
        $x = $this->ranked('orphan', 2000, ['realm' => 'unknown-realm']);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $row = $this->rankOf($x);
        $this->assertNull($row->connected_realm_id);
        $this->assertNull($row->realm_rank);
        $this->assertNull($row->realm_pop);
        $this->assertSame(1, $row->region_rank);
    }

    public function test_null_spec_gets_null_spec_rank(): void
    {
        $x = $this->ranked('nospec', 2000, ['active_specialization_id' => null]);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertNull($this->rankOf($x)->spec_rank);
        $this->assertNull($this->rankOf($x)->spec_pop);
    }

    public function test_rerun_replaces_only_the_current_season_rows(): void
    {
        $x = $this->ranked('first', 2000);
        $frozen = $this->ranked('frozen', 2500, ['rating_season_id' => 17]);
        CharacterRank::create([
            'character_id' => $frozen->id, 'season_id' => 17, 'region' => 'eu', 'connected_realm_id' => 1403,
            'class_id' => 9, 'spec_id' => 267, 'rating' => 2500,
            'world_rank' => 1, 'region_rank' => 1, 'realm_rank' => 1, 'class_rank' => 1, 'spec_rank' => 1,
            'world_pop' => 1, 'region_pop' => 1, 'realm_pop' => 1, 'class_pop' => 1, 'spec_pop' => 1,
            'computed_at' => '2026-08-21 04:00:00',
        ]);
        $this->artisan('ranks:materialize')->assertExitCode(0);
        $x->update(['rating_season_id' => 17]); // last-season rating resurfaced
        $this->ranked('second', 2100);

        $this->artisan('ranks:materialize')
            ->expectsOutputToContain('Ranked 1 characters')
            ->assertExitCode(0);

        $this->assertSame(['second'], CharacterRank::query()->where('season_id', 18)->with('character')->get()->pluck('character.name')->all());
        $this->assertSame(['frozen'], CharacterRank::query()->where('season_id', 17)->with('character')->get()->pluck('character.name')->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->ranked('dry', 2000);

        $this->artisan('ranks:materialize', ['--dry-run' => true])
            ->expectsOutputToContain('1 characters would be ranked')
            ->assertExitCode(0);

        $this->assertSame(0, CharacterRank::count());
        $this->assertNull(Cache::get('ranks:computed_at'));
    }

    public function test_no_current_season_is_a_failure_exit_but_a_missing_started_at_is_fine(): void
    {
        GameDataSeason::query()->update(['started_at' => null]);
        Seasons::clearCache();
        $this->ranked('nostart', 2000);
        $this->artisan('ranks:materialize')->assertExitCode(0);
        $this->assertSame(1, CharacterRank::count());

        GameDataSeason::query()->update(['is_current' => false]);
        Seasons::clearCache();

        $this->artisan('ranks:materialize')->assertExitCode(1);
    }
}
