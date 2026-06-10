<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Blizzard\Jobs\SyncGuildData;
use App\Models\Guild;
use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RaiderIOSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_seed_guilds_dispatches_one_sync_per_ref(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topGuilds')->with('eu', 3)->andReturn((function () {
            yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
            yield new SeedGuildRef('eu', 'twisting-nether', 'Method');
            yield new SeedGuildRef('eu', 'stormscale', 'Pieces');
        })());

        $seeder = app(RaiderIOSeeder::class);
        $opts = new SeedOptions(regions: ['eu'], limit: 3);

        $report = $seeder->seedGuilds($opts);

        Queue::assertPushed(SyncGuildData::class, 3);
        $this->assertSame(3, $report->considered);
        $this->assertSame(3, $report->dispatched);
        $this->assertSame(0, $report->skippedTtl);
    }

    public function test_seed_guilds_skips_fresh_guilds(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topGuilds')->andReturn((function () {
            yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
        })());

        Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'Echo',
            'roster_synced_at' => now()->subMinutes(5),
        ]);

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu'], limit: 1));

        Queue::assertNothingPushed();
        $this->assertSame(1, $report->considered);
        $this->assertSame(0, $report->dispatched);
        $this->assertSame(1, $report->skippedTtl);
    }

    public function test_seed_guilds_force_bypasses_ttl_skip(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topGuilds')->andReturn((function () {
            yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
        })());

        Guild::factory()->create([
            'region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo',
            'roster_synced_at' => now()->subMinutes(5),
        ]);

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu'], limit: 1, force: true));

        Queue::assertPushed(SyncGuildData::class, 1);
        $this->assertSame(1, $report->dispatched);
    }

    public function test_seed_guilds_dry_run_dispatches_nothing(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topGuilds')->andReturn((function () {
            yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
            yield new SeedGuildRef('eu', 'twisting-nether', 'Method');
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu'], limit: 2, dryRun: true));

        Queue::assertNothingPushed();
        $this->assertSame(2, $report->considered);
        $this->assertSame(2, $report->dispatched); // counter still increments under dry-run
    }

    public function test_seed_guilds_isolates_per_region_errors(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topGuilds')->with('eu', 1)->andReturn((function () {
            yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
        })());
        $client->shouldReceive('topGuilds')->with('us', 1)->andThrow(
            new RaiderIOException('boom')
        );

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu', 'us'], limit: 1));

        Queue::assertPushed(SyncGuildData::class, 1);
        $this->assertSame(1, $report->dispatched);
        $this->assertSame(1, $report->errors);
    }
}
