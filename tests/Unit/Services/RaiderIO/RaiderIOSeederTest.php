<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Blizzard\Jobs\SyncGuildData;
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
}
