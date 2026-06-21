<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Middleware;

use App\Blizzard\Middleware\BlizzardRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P1.10: the 429 circuit-breaker counter must trip at the threshold and carry a
 * window TTL — `Cache::add($key, 0, $window)` before incrementing guarantees the
 * count expires instead of accumulating forever after a mid-method crash.
 */
class BlizzardRateLimiterBreakerTest extends TestCase
{
    private function record(): void
    {
        $mw = new BlizzardRateLimiter;
        (new ReflectionMethod($mw, 'recordRateLimitHit'))->invoke($mw);
    }

    public function test_trips_circuit_at_threshold_and_clears_count(): void
    {
        config([
            'blizzard.rate_limit.circuit_breaker.threshold' => 3,
            'blizzard.rate_limit.circuit_breaker.window' => 120,
            'blizzard.rate_limit.circuit_breaker.cooldown' => 60,
        ]);
        Cache::flush();

        $this->record();
        $this->record();
        $this->assertFalse(Cache::has('blizzard:unhealthy'));
        $this->assertSame(2, (int) Cache::get('blizzard:429-count'));

        $this->record(); // hits the threshold
        $this->assertTrue(Cache::has('blizzard:unhealthy'));
        $this->assertFalse(Cache::has('blizzard:429-count'), 'count is cleared once the circuit trips');
    }

    public function test_count_expires_after_the_window(): void
    {
        config([
            'blizzard.rate_limit.circuit_breaker.threshold' => 5,
            'blizzard.rate_limit.circuit_breaker.window' => 120,
        ]);
        Cache::flush();
        Carbon::setTestNow('2026-06-01 12:00:00');

        $this->record();
        $this->assertSame(1, (int) Cache::get('blizzard:429-count'));

        // Past the window the count must be gone, so a later burst starts fresh.
        Carbon::setTestNow('2026-06-01 12:02:01');
        $this->assertFalse(Cache::has('blizzard:429-count'));
    }

    public function test_default_job_rate_accounts_for_calls_per_job(): void
    {
        // The throttle counts JOBS, not HTTP requests, and a Full sync makes
        // ~15-25 calls. The old default of 80 jobs/s allowed >1000 req/s. The
        // budgeted default must stay well below Blizzard's ~100 req/s ceiling. (P2.4)
        $this->assertLessThanOrEqual(20, (int) config('blizzard.rate_limit.per_second'));
    }
}
