<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\FetchLadderShard;
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataPeriod;
use App\Models\GameDataSeason;
use App\Services\RaiderIO\RaiderIOClient;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeedLaddersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'blizzard.mplus_leaderboard.enabled' => true,
            'blizzard.mplus_leaderboard.regions' => ['eu'],
        ]);

        GameDataPeriod::create(['period_id' => 1002, 'region' => 'eu', 'start_at' => now()->subDay(), 'end_at' => now()->addWeek()]);
        GameDataConnectedRealm::create(['connected_realm_id' => 509, 'region' => 'eu', 'realm_slugs' => ['a']]);
        GameDataConnectedRealm::create(['connected_realm_id' => 1305, 'region' => 'eu', 'realm_slugs' => ['b']]);

        GameDataSeason::create([
            'id' => 17,
            'slug' => 'season-mn-1',
            'name' => 'Midnight Season 1',
            'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11,
            'is_current' => true,
        ]);
        Seasons::clearCache();

        // Blizzard's mythic-keystone season detail carries no `dungeons` key —
        // the pool comes from raider.io static-data, keyed by challenge_mode_id.
        $this->fakeStaticData([
            'seasons' => [
                ['slug' => 'season-tww-4', 'dungeons' => [['challenge_mode_id' => 1], ['challenge_mode_id' => 2]]],
                ['slug' => 'season-mn-1', 'dungeons' => [
                    ['challenge_mode_id' => 402],
                    ['challenge_mode_id' => 558],
                    ['challenge_mode_id' => 560],
                ]],
            ],
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function fakeStaticData(array $payload): void
    {
        $client = $this->createMock(RaiderIOClient::class);
        $client->method('mythicPlusStaticData')->willReturn($payload);
        $this->app->instance(RaiderIOClient::class, $client);
    }

    private function seedFallbackDungeons(): void
    {
        GameDataMythicKeystoneDungeon::create(['id' => 900, 'name' => 'Old One']);
        GameDataMythicKeystoneDungeon::create(['id' => 901, 'name' => 'Older One']);
    }

    public function test_dispatches_one_job_per_realm_dungeon_pair(): void
    {
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 6); // 2 realms × 3 dungeons
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->periodId === 1002 && $job->region === 'eu');
    }

    public function test_dungeon_pool_comes_from_the_matching_raiderio_season(): void
    {
        $this->seedFallbackDungeons(); // must be ignored — the season pool wins
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 6);
        foreach ([402, 558, 560] as $dungeonId) {
            Queue::assertPushed(fn (FetchLadderShard $job) => $job->dungeonId === $dungeonId);
        }
        Queue::assertNotPushed(FetchLadderShard::class, fn (FetchLadderShard $job) => $job->dungeonId === 900);
        Queue::assertNotPushed(FetchLadderShard::class, fn (FetchLadderShard $job) => $job->dungeonId === 1);
    }

    public function test_empty_static_data_falls_back_to_all_known_dungeons_with_a_warning(): void
    {
        $this->fakeStaticData([]);
        $this->seedFallbackDungeons();
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')
            ->expectsOutputToContain('Falling back to full game_data_mythic_keystone_dungeons table')
            ->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 4); // 2 realms × 2 table rows
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->dungeonId === 900);
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->dungeonId === 901);
    }

    public function test_unmatched_season_slug_falls_back_to_all_known_dungeons(): void
    {
        $this->fakeStaticData(['seasons' => [['slug' => 'season-tww-4', 'dungeons' => [['challenge_mode_id' => 1]]]]]);
        $this->seedFallbackDungeons();
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 4);
        Queue::assertNotPushed(FetchLadderShard::class, fn (FetchLadderShard $job) => $job->dungeonId === 1);
    }

    public function test_raiderio_failure_falls_back_to_all_known_dungeons(): void
    {
        $client = $this->createMock(RaiderIOClient::class);
        $client->method('mythicPlusStaticData')->willThrowException(new \RuntimeException('raider.io down'));
        $this->app->instance(RaiderIOClient::class, $client);
        $this->seedFallbackDungeons();
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')
            ->expectsOutputToContain('raider.io down')
            ->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 4);
    }

    public function test_kill_switch_dispatches_nothing(): void
    {
        config(['blizzard.mplus_leaderboard.enabled' => false]);
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Queue::fake();

        $this->artisan('blizzard:seed-ladders', ['--dry-run' => true])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_just_ended_period_is_crawled_alongside_the_current_one(): void
    {
        GameDataPeriod::create([
            'period_id' => 1001,
            'region' => 'eu',
            'start_at' => now()->subDays(8),
            'end_at' => now()->subHours(12),
        ]);
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 12); // 2 periods × 2 realms × 3 dungeons
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->periodId === 1002);
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->periodId === 1001);
    }

    public function test_long_finished_period_is_not_crawled(): void
    {
        GameDataPeriod::create([
            'period_id' => 1001,
            'region' => 'eu',
            'start_at' => now()->subDays(12),
            'end_at' => now()->subDays(5),
        ]);
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 6); // current period only
        Queue::assertNotPushed(FetchLadderShard::class, fn (FetchLadderShard $job) => $job->periodId === 1001);
    }

    public function test_period_override_targets_only_that_period(): void
    {
        GameDataPeriod::create([
            'period_id' => 1001,
            'region' => 'eu',
            'start_at' => now()->subDays(8),
            'end_at' => now()->subHours(12),
        ]);
        Queue::fake();

        $this->artisan('blizzard:seed-ladders', ['--period' => 999])->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 6);
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->periodId === 999);
    }

    public function test_missing_period_skips_region_with_error(): void
    {
        GameDataPeriod::query()->delete();
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_static_data_is_fetched_once_per_run_across_regions(): void
    {
        config(['blizzard.mplus_leaderboard.regions' => ['eu', 'us']]);
        GameDataPeriod::create(['period_id' => 2002, 'region' => 'us', 'start_at' => now()->subDay(), 'end_at' => now()->addWeek()]);
        GameDataConnectedRealm::create(['connected_realm_id' => 11, 'region' => 'us', 'realm_slugs' => ['c']]);

        $client = $this->createMock(RaiderIOClient::class);
        $client->expects($this->once())
            ->method('mythicPlusStaticData')
            ->willReturn(['seasons' => [['slug' => 'season-mn-1', 'dungeons' => [['challenge_mode_id' => 402]]]]]);
        $this->app->instance(RaiderIOClient::class, $client);
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 3); // (2 eu + 1 us realms) × 1 dungeon
    }
}
