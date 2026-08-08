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

    public function test_missing_period_skips_region_with_error(): void
    {
        GameDataPeriod::query()->delete();
        Queue::fake();

        $this->artisan('blizzard:seed-ladders')->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
