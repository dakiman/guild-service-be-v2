<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\LadderRun;
use App\Models\MetaSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WarmMetaStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['blizzard.mplus_leaderboard.regions' => ['eu']]);
    }

    private function seedRun(int $periodId, string $hashSuffix): void
    {
        LadderRun::create([
            'period_id' => $periodId, 'region' => 'eu', 'dungeon_id' => 504,
            'keystone_level' => 15, 'duration' => 1650000,
            'completed_timestamp' => 1754300000000, 'is_completed_on_time' => true,
            'comp_signature' => null, 'run_hash' => sha1($hashSuffix),
        ]);
    }

    public function test_warms_two_most_recent_periods_with_data(): void
    {
        $this->seedRun(1000, 'a');
        $this->seedRun(1001, 'b');
        $this->seedRun(1002, 'c');

        $this->artisan('meta:warm')->assertExitCode(0);

        // 2 periods × 2 scopes (all, eu) × 3 sections
        $this->assertSame(12, MetaSnapshot::count());
        $this->assertSame(0, MetaSnapshot::where('period_id', 1000)->count());
    }

    public function test_period_override(): void
    {
        $this->seedRun(1000, 'a');

        $this->artisan('meta:warm', ['--period' => 1000])->assertExitCode(0);

        $this->assertSame(6, MetaSnapshot::count());
    }

    public function test_no_data_is_a_noop(): void
    {
        $this->artisan('meta:warm')->assertExitCode(0);
        $this->assertSame(0, MetaSnapshot::count());
    }

    public function test_scheduled_run_defers_while_the_crawl_is_draining(): void
    {
        Carbon::setTestNow(now()->setTime(6, 0));
        $today = now()->format('Y-m-d');
        Cache::put("ladder-crawl:{$today}:dispatched", 10, now()->addHours(48));
        Cache::put("ladder-crawl:{$today}:fetched", 3, now()->addHours(48));
        $this->seedRun(1002, 'a');

        $this->artisan('meta:warm')->assertExitCode(0);

        $this->assertSame(0, MetaSnapshot::count());
        Carbon::setTestNow();
    }

    public function test_scheduled_run_warms_once_when_drained(): void
    {
        $today = now()->format('Y-m-d');
        Cache::put("ladder-crawl:{$today}:dispatched", 10, now()->addHours(48));
        Cache::put("ladder-crawl:{$today}:fetched", 10, now()->addHours(48));
        $this->seedRun(1002, 'a');

        $this->artisan('meta:warm')->assertExitCode(0);
        $this->assertSame(6, MetaSnapshot::count());
        $this->assertArrayNotHasKey('coverage', MetaSnapshot::first()->payload);

        // Same day again: already-warmed flag short-circuits.
        $this->seedRun(1002, 'b');
        $this->artisan('meta:warm')->assertExitCode(0);
        $this->assertSame(6, MetaSnapshot::count());
    }

    public function test_deadline_forces_a_partial_warm_with_coverage_stamp(): void
    {
        Carbon::setTestNow(now()->setTime(11, 30));
        $today = now()->format('Y-m-d');
        Cache::put("ladder-crawl:{$today}:dispatched", 10, now()->addHours(48));
        Cache::put("ladder-crawl:{$today}:fetched", 3, now()->addHours(48));
        $this->seedRun(1002, 'a');

        $this->artisan('meta:warm')->assertExitCode(0);

        $this->assertSame(6, MetaSnapshot::count());
        $this->assertSame(['fetched' => 3, 'dispatched' => 10], MetaSnapshot::first()->payload['coverage']);
        Carbon::setTestNow();
    }

    public function test_period_override_bypasses_the_guards(): void
    {
        $today = now()->format('Y-m-d');
        Cache::put("ladder-crawl:{$today}:dispatched", 10, now()->addHours(48));
        Cache::put("ladder-crawl:{$today}:fetched", 3, now()->addHours(48));
        $this->seedRun(1000, 'a');

        $this->artisan('meta:warm', ['--period' => 1000])->assertExitCode(0);

        $this->assertSame(6, MetaSnapshot::count());
    }
}
