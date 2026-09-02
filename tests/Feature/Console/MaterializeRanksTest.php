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
            'rating_synced_at' => '2026-08-25 12:00:00',
        ], $overrides));
    }

    public function test_ranks_every_scope_with_competition_ranking_and_populations(): void
    {
        $a = $this->ranked('alpha', 3000);
        $b = $this->ranked('bravo', 2500, ['realm' => 'tarren-mill', 'class_id' => 12, 'active_specialization_id' => 581]);
        $c = $this->ranked('charlie', 2500, ['realm' => 'dentarg']);
        $d = $this->ranked('delta', 2000, ['region' => 'us', 'realm' => 'illidan']);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertSame(4, CharacterRank::count());

        $ra = CharacterRank::find($a->id);
        $this->assertSame([1, 1, 1, 1, 1], [$ra->world_rank, $ra->region_rank, $ra->realm_rank, $ra->class_rank, $ra->spec_rank]);
        $this->assertSame([4, 3, 1, 2, 2], [$ra->world_pop, $ra->region_pop, $ra->realm_pop, $ra->class_pop, $ra->spec_pop]);
        $this->assertSame(1403, $ra->connected_realm_id);
        $this->assertSame(18, $ra->season_id);

        // Tie at 2500: both rank 2 in world/region; delta (2000) is world rank 4 (competition ranking skips 3).
        $this->assertSame(2, CharacterRank::find($b->id)->world_rank);
        $this->assertSame(2, CharacterRank::find($c->id)->world_rank);
        $this->assertSame(4, CharacterRank::find($d->id)->world_rank);
        $this->assertSame(1, CharacterRank::find($d->id)->region_rank);

        // bravo + charlie share connected realm 1084 → realm pop 2, both realm rank 1.
        $rb = CharacterRank::find($b->id);
        $this->assertSame(1084, $rb->connected_realm_id);
        $this->assertSame(2, $rb->realm_pop);
        $this->assertSame(1, $rb->realm_rank);
        $this->assertSame(1, $rb->class_rank);   // only DH in EU
        $this->assertSame(1, $rb->spec_rank);

        $this->assertNotNull(Cache::get('ranks:computed_at'));
    }

    public function test_population_gate_excludes_stale_unrated_and_sub_endgame(): void
    {
        $this->ranked('fresh', 2000);
        $this->ranked('stale', 2900, ['rating_synced_at' => '2026-08-01 00:00:00']);
        $this->ranked('never', 2900, ['rating_synced_at' => null]);
        $this->ranked('unrated', 0);
        $this->ranked('lowbie', 2900, ['level' => 80]);
        $this->ranked('classic', 2900, ['game_version' => 'classic']);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertSame(['fresh'], CharacterRank::query()->with('character')->get()->pluck('character.name')->all());
    }

    public function test_unmapped_realm_gets_null_realm_rank_but_other_ranks(): void
    {
        $x = $this->ranked('orphan', 2000, ['realm' => 'unknown-realm']);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $row = CharacterRank::find($x->id);
        $this->assertNull($row->connected_realm_id);
        $this->assertNull($row->realm_rank);
        $this->assertNull($row->realm_pop);
        $this->assertSame(1, $row->region_rank);
    }

    public function test_null_spec_gets_null_spec_rank(): void
    {
        $x = $this->ranked('nospec', 2000, ['active_specialization_id' => null]);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertNull(CharacterRank::find($x->id)->spec_rank);
        $this->assertNull(CharacterRank::find($x->id)->spec_pop);
    }

    public function test_rerun_replaces_previous_rows(): void
    {
        $x = $this->ranked('first', 2000);
        $this->artisan('ranks:materialize')->assertExitCode(0);
        $x->update(['rating_synced_at' => '2026-08-01 00:00:00']); // now stale
        $this->ranked('second', 2100);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertSame(['second'], CharacterRank::query()->with('character')->get()->pluck('character.name')->all());
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

    public function test_no_current_season_is_a_failure_exit(): void
    {
        GameDataSeason::query()->update(['is_current' => false]);
        Seasons::clearCache();

        $this->artisan('ranks:materialize')->assertExitCode(1);
    }
}
