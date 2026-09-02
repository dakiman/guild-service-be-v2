<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use App\Models\CharacterRank;
use App\Models\GameDataPeriod;
use App\Models\GameDataSeason;
use App\Models\RealmRunBoard;
use App\Models\RealmSlugMap;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LeaderboardEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forever('ranks:computed_at', '2026-09-01T04:00:00+00:00');
        RealmSlugMap::create(['region' => 'eu', 'realm_slug' => 'draenor', 'connected_realm_id' => 1403]);
        RealmSlugMap::create(['region' => 'eu', 'realm_slug' => 'tarren-mill', 'connected_realm_id' => 1084]);
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

    private function ranked(string $name, int $rating, int $regionRank, array $extra = []): Character
    {
        $c = Character::factory()->create(array_merge([
            'name' => $name, 'display_name' => ucfirst($name), 'region' => 'eu', 'realm' => 'draenor', 'level' => 90,
            'class_id' => 9, 'active_specialization_id' => 267, 'faction' => 'Horde',
            'mythic_plus_rating' => $rating, 'mythic_plus_rating_color' => '#ff8000',
        ], $extra['character'] ?? []));
        CharacterRank::create(array_merge([
            'character_id' => $c->id, 'season_id' => 18, 'region' => 'eu', 'connected_realm_id' => 1403,
            'class_id' => 9, 'spec_id' => 267, 'rating' => $rating,
            'world_rank' => $regionRank, 'region_rank' => $regionRank, 'realm_rank' => $regionRank,
            'class_rank' => $regionRank, 'spec_rank' => $regionRank,
            'world_pop' => 3, 'region_pop' => 3, 'realm_pop' => 3, 'class_pop' => 3, 'spec_pop' => 3,
            'computed_at' => now(),
        ], $extra['rank'] ?? []));

        return $c;
    }

    public function test_region_scope_returns_rows_in_rank_then_name_order_with_meta(): void
    {
        $this->ranked('zed', 2500, 2);
        $this->ranked('amy', 2500, 2);
        $this->ranked('top', 3000, 1);

        $res = $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu');

        $res->assertOk();
        $this->assertSame(['top', 'amy', 'zed'], array_column($res->json('data.*.character'), 'name'));
        $this->assertSame([1, 2, 2], $res->json('data.*.rank'));
        $res->assertJsonPath('data.0.rating', 3000)
            ->assertJsonPath('data.0.color', '#ff8000')
            ->assertJsonPath('data.0.character.display_name', 'Top')
            ->assertJsonPath('data.0.character.spec_id', 267)
            ->assertJsonPath('meta.scope', 'region')
            ->assertJsonPath('meta.population', 3)
            ->assertJsonPath('meta.season_id', 18)
            ->assertJsonPath('meta.computed_at', '2026-09-01T04:00:00+00:00')
            ->assertJsonPath('meta.season.slug', 'season-mn-2')
            ->assertJsonPath('meta.season.is_current', true);
    }

    public function test_season_slug_selects_a_frozen_season_with_row_derived_stamp(): void
    {
        $this->ranked('now', 3000, 1);
        $this->ranked('then', 2600, 1, ['rank' => ['season_id' => 17, 'computed_at' => '2026-08-21 04:00:00']]);

        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.character.name', 'now')
            ->assertJsonPath('meta.season_id', 18)
            ->assertJsonPath('meta.computed_at', '2026-09-01T04:00:00+00:00');

        $res = $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu&season=season-mn-1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.character.name', 'then')
            ->assertJsonPath('meta.season.id', 17)
            ->assertJsonPath('meta.season.name', 'Midnight Season 1')
            ->assertJsonPath('meta.season.is_current', false)
            ->assertJsonPath('meta.season_id', 17);
        $this->assertStringStartsWith('2026-08-21T04:00:00', $res->json('meta.computed_at'));

        $this->getJson('/api/v1/leaderboards/characters?scope=world&season=season-mn-1')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unknown_season_is_404_and_empty_frozen_season_has_null_stamp(): void
    {
        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu&season=season-nope')
            ->assertNotFound()->assertJsonPath('message', 'Unknown season');

        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu&season=season-mn-1')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.population', 0)
            ->assertJsonPath('meta.computed_at', null);
    }

    public function test_realm_scope_resolves_slug_to_connected_realm(): void
    {
        $this->ranked('a', 3000, 1);
        $this->ranked('b', 2000, 2, ['rank' => ['connected_realm_id' => 1084, 'realm_rank' => 1]]);

        $res = $this->getJson('/api/v1/leaderboards/characters?scope=realm&region=eu&realm=tarren-mill');

        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.character.name', 'b')
            ->assertJsonPath('meta.connected_realm_id', 1084)->assertJsonPath('meta.realm', 'tarren-mill');
    }

    public function test_unknown_realm_is_404(): void
    {
        $this->getJson('/api/v1/leaderboards/characters?scope=realm&region=eu&realm=nowhere')->assertNotFound();
    }

    public function test_validation_rejects_missing_scope_params(): void
    {
        $this->getJson('/api/v1/leaderboards/characters?scope=realm&region=eu')->assertStatus(422);
        $this->getJson('/api/v1/leaderboards/characters?scope=class&region=eu')->assertStatus(422);
        $this->getJson('/api/v1/leaderboards/characters?scope=region')->assertStatus(422);
        $this->getJson('/api/v1/leaderboards/characters?scope=bogus&region=eu')->assertStatus(422);
        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=zz')->assertStatus(422);
    }

    public function test_world_scope_needs_no_region_and_default_scope_is_region(): void
    {
        $this->ranked('a', 3000, 1);
        $this->getJson('/api/v1/leaderboards/characters?scope=world')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/leaderboards/characters?region=eu')->assertOk()->assertJsonPath('meta.scope', 'region');
    }

    public function test_cap_is_100_rows(): void
    {
        for ($i = 1; $i <= 105; $i++) {
            $this->ranked("c{$i}", 3000 - $i, $i);
        }
        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu')->assertOk()->assertJsonCount(100, 'data');
    }

    public function test_cache_key_follows_computed_at_stamp(): void
    {
        $this->ranked('a', 3000, 1);
        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu')->assertJsonCount(1, 'data');

        $this->ranked('b', 2000, 2);
        // Same stamp → cached page still has 1 row.
        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu')->assertJsonCount(1, 'data');

        Cache::forever('ranks:computed_at', '2026-09-02T04:00:00+00:00');
        $this->getJson('/api/v1/leaderboards/characters?scope=region&region=eu')->assertJsonCount(2, 'data');
    }

    public function test_realm_runs_returns_current_period_board_or_empty(): void
    {
        GameDataPeriod::create(['period_id' => 1078, 'region' => 'eu', 'start_at' => now()->subDay(), 'end_at' => now()->addDays(6)]);

        $this->getJson('/api/v1/leaderboards/realm-runs?region=eu&realm=draenor')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.period_id', 1078);

        RealmRunBoard::create([
            'period_id' => 1078, 'region' => 'eu', 'connected_realm_id' => 1403,
            'payload' => [['id' => 7, 'dungeon_id' => 504, 'dungeon_name' => 'X', 'keystone_level' => 15, 'duration' => 1, 'is_completed_on_time' => true, 'affixes' => [], 'completed_at' => 1, 'members' => []]],
            'computed_at' => '2026-09-01 04:05:00',
        ]);
        Cache::forever('ranks:computed_at', '2026-09-01T04:05:00+00:00');

        $this->getJson('/api/v1/leaderboards/realm-runs?region=eu&realm=draenor')
            ->assertOk()->assertJsonPath('data.0.id', 7)->assertJsonPath('meta.connected_realm_id', 1403);

        $this->getJson('/api/v1/leaderboards/realm-runs?region=eu&realm=nowhere')->assertNotFound();
        $this->getJson('/api/v1/leaderboards/realm-runs?region=eu')->assertStatus(422);
    }
}
