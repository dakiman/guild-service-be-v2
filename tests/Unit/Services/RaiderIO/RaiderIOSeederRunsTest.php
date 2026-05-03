<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\SeededRun;
use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedRunRef;
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
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) =>
            $j->name === 'Alice' && $j->depth === SyncDepth::Full);

        $this->assertSame(2, $report->considered);
        $this->assertSame(3, $report->dispatched);
        $this->assertSame(0, $report->skippedDedupe);

        $this->assertTrue(SeededRun::where('keystone_run_id', 1001)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1002)->exists());
    }

    public function test_seed_runs_skips_already_seeded_runs(): void
    {
        SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'Alice')]);
            yield new SeedRunRef(1002, 'eu', [new SeedCharacterRef('eu', 'kazzak', 'Bob')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

        Bus::assertDispatched(SyncCharacterData::class, 1);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Bob');

        $this->assertSame(2, $report->considered);
        $this->assertSame(1, $report->dispatched);
        $this->assertSame(1, $report->skippedDedupe);
    }

    public function test_seed_runs_skips_fresh_characters(): void
    {
        \App\Models\Character::factory()->create([
            'name' => 'fresh-bob',
            'realm' => 'tarren-mill',
            'region' => 'eu',
        ]);
        \App\Models\Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
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
        \App\Models\Character::factory()->create([
            'name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu',
        ]);
        \App\Models\Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
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

    public function test_seed_runs_dry_run_dispatches_nothing_but_writes_ledger(): void
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
        $this->assertTrue(\App\Models\SeededRun::where('keystone_run_id', 1001)->exists());
    }
}
