<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\SeededRun;
use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedRunRef;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RaiderIOSeederRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_seed_runs_dispatches_full_per_member_and_persists_ledger(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->with('eu', 'season-mn-1', 1)->andReturn((function () {
            yield new SeedRunRef(
                keystoneRunId: 1001,
                region: 'eu',
                members: [
                    new SeedCharacterRef('eu', 'tarren-mill', 'Alice'),
                    new SeedCharacterRef('eu', 'tarren-mill', 'Bob'),
                ],
            );
            yield new SeedRunRef(
                keystoneRunId: 1002,
                region: 'eu',
                members: [
                    new SeedCharacterRef('eu', 'kazzak', 'Cara'),
                ],
            );
        })());

        $seeder = app(RaiderIOSeeder::class);
        $opts = new SeedOptions(regions: ['eu'], limit: 1);

        $report = $seeder->seedRuns($opts);

        Bus::assertDispatched(SyncCharacterData::class, 3);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Alice' && $j->depth === SyncDepth::Full);

        $this->assertSame(2, $report->considered);
        $this->assertSame(3, $report->dispatched);
        $this->assertSame(0, $report->skippedDedupe);

        $this->assertTrue(SeededRun::where('keystone_run_id', 1001)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1002)->exists());
    }

    public function test_seed_runs_counts_ledgered_runs_but_still_dispatches_their_members(): void
    {
        SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'Alice')]);
            yield new SeedRunRef(1002, 'eu', [new SeedCharacterRef('eu', 'kazzak', 'Bob')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

        Bus::assertDispatched(SyncCharacterData::class, 2);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Alice');
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Bob');

        $this->assertSame(2, $report->considered);
        $this->assertSame(2, $report->dispatched);
        $this->assertSame(1, $report->skippedDedupe);
        $this->assertSame(1, SeededRun::where('keystone_run_id', 1001)->count()); // no duplicate ledger row
    }

    public function test_seed_runs_dispatches_stale_members_of_ledgered_runs_and_skips_fresh_ones(): void
    {
        SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);
        Character::factory()->create([
            'name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu',
        ]);
        Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
            ->update(['updated_at' => now()->subMinutes(5)]);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [
                new SeedCharacterRef('eu', 'tarren-mill', 'fresh-bob'),
                new SeedCharacterRef('eu', 'kazzak', 'Newbie'),
            ]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

        // The run is ledgered (dedupe-counted, no re-insert) but its members are
        // still individually gated: fresh-bob is inside the TTL, Newbie has no row.
        Bus::assertDispatched(SyncCharacterData::class, 1);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Newbie');
        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'fresh-bob');

        $this->assertSame(1, $report->skippedDedupe);
        $this->assertSame(1, $report->skippedTtl);
        $this->assertSame(1, $report->dispatched);
        $this->assertSame(1, SeededRun::count());
    }

    public function test_seed_runs_skips_fresh_characters(): void
    {
        Character::factory()->create([
            'name' => 'fresh-bob',
            'realm' => 'tarren-mill',
            'region' => 'eu',
        ]);
        Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
            ->update(['updated_at' => now()->subMinutes(5)]);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(
                keystoneRunId: 1001,
                region: 'eu',
                members: [
                    new SeedCharacterRef('eu', 'tarren-mill', 'fresh-bob'),
                    new SeedCharacterRef('eu', 'kazzak', 'Stale'),
                ],
            );
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

        Bus::assertDispatched(SyncCharacterData::class, 1);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Stale');
        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'fresh-bob');

        $this->assertSame(1, $report->dispatched);
        $this->assertSame(1, $report->skippedTtl);
    }

    public function test_seed_runs_force_bypasses_character_ttl_skip(): void
    {
        Character::factory()->create([
            'name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu',
        ]);
        Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
            ->update(['updated_at' => now()->subMinutes(5)]);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'fresh-bob')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, force: true));

        Bus::assertDispatched(SyncCharacterData::class, 1);
        $this->assertSame(1, $report->dispatched);
        $this->assertSame(0, $report->skippedTtl);
    }

    public function test_seed_runs_dry_run_dispatches_nothing_and_does_not_mutate_ledger(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [
                new SeedCharacterRef('eu', 'tarren-mill', 'A'),
                new SeedCharacterRef('eu', 'tarren-mill', 'B'),
            ]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, dryRun: true));

        Bus::assertNothingDispatched();
        $this->assertSame(2, $report->dispatched);
        // Dry-run does NOT write to the ledger so a subsequent real run sees fresh runs.
        $this->assertFalse(SeededRun::where('keystone_run_id', 1001)->exists());
    }

    public function test_seed_runs_isolates_per_region_errors(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->with('eu', 'season-mn-1', 1)->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'A')]);
        })());
        $client->shouldReceive('topRuns')->with('us', 'season-mn-1', 1)->andThrow(
            new RaiderIOException('boom')
        );

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu', 'us'], limit: 1));

        Bus::assertDispatched(SyncCharacterData::class, 1);
        $this->assertSame(1, $report->dispatched);
        $this->assertSame(1, $report->errors);
    }

    public function test_seed_runs_threads_teammate_crawl_into_dispatched_jobs(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'A')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, teammateCrawl: true));

        // The dispatched job carries forceTeammateCrawl=true so the worker bypasses
        // the global BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED kill-switch.
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'A' && $j->forceTeammateCrawl === true);
    }

    public function test_seed_runs_does_not_force_teammate_crawl_by_default(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'A')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->forceTeammateCrawl === false);
    }

    public function test_seed_runs_dry_run_counts_ledgered_runs_without_writing(): void
    {
        SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'A')]);
            yield new SeedRunRef(1002, 'eu', [new SeedCharacterRef('eu', 'kazzak', 'B')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, dryRun: true));

        Bus::assertNothingDispatched();
        $this->assertSame(1, $report->skippedDedupe);
        $this->assertSame(2, $report->dispatched); // both members counted; neither is fresh
        // Dry-run never mutates the ledger.
        $this->assertFalse(SeededRun::where('keystone_run_id', 1002)->exists());
        $this->assertSame(1, SeededRun::count());
    }
}
