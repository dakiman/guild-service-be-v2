<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GameDataPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_for_returns_latest_started_period_for_region(): void
    {
        GameDataPeriod::create(['period_id' => 1001, 'region' => 'eu', 'start_at' => now()->subWeeks(2), 'end_at' => now()->subWeek()]);
        GameDataPeriod::create(['period_id' => 1002, 'region' => 'eu', 'start_at' => now()->subDay(), 'end_at' => now()->addWeek()]);
        GameDataPeriod::create(['period_id' => 1003, 'region' => 'eu', 'start_at' => now()->addWeek(), 'end_at' => now()->addWeeks(2)]);
        GameDataPeriod::create(['period_id' => 1002, 'region' => 'us', 'start_at' => now()->subDay(), 'end_at' => now()->addWeek()]);

        $this->assertSame(1002, GameDataPeriod::currentFor('eu')?->period_id);
        $this->assertNull(GameDataPeriod::currentFor('kr'));
    }
}
