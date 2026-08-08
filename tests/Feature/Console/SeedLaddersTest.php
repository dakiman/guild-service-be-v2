<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\GameDataClientFactory;
use App\Blizzard\Jobs\FetchLadderShard;
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataPeriod;
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

        $client = $this->createMock(BlizzardGameDataClient::class);
        $client->method('getCurrentMythicPlusSeason')->willReturn(17);
        $client->method('getMythicKeystoneSeason')->willReturn([
            'id' => 17,
            'dungeons' => [['id' => 504], ['id' => 505], ['id' => 506]],
        ]);
        $factory = $this->createMock(GameDataClientFactory::class);
        $factory->method('forRegion')->willReturn($client);
        $this->app->instance(GameDataClientFactory::class, $factory);
    }

    public function test_dispatches_one_job_per_realm_dungeon_pair(): void
    {
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertPushed(FetchLadderShard::class, 6); // 2 realms × 3 dungeons
        Queue::assertPushed(fn (FetchLadderShard $job) => $job->periodId === 1002 && $job->region === 'eu');
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
}
