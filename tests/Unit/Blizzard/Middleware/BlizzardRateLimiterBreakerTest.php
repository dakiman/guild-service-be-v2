<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Middleware;

use App\Blizzard\Exceptions\BlizzardThrottleTimeoutException;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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

    private function fakeJob(): object
    {
        return new class
        {
            public array $released = [];

            public function release(int $delay): void
            {
                $this->released[] = $delay;
            }
        };
    }

    public function test_passes_job_through_without_job_level_throttle(): void
    {
        $job = $this->fakeJob();
        $ran = false;

        (new BlizzardRateLimiter)->handle($job, function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertSame([], $job->released);
    }

    public function test_429_releases_with_retry_after_and_records_hit(): void
    {
        Cache::flush();
        $job = $this->fakeJob();

        $exception = new RequestException(new Response(new Psr7Response(429, ['Retry-After' => '7'])));

        (new BlizzardRateLimiter)->handle($job, function () use ($exception) {
            throw $exception;
        });

        $this->assertSame([7], $job->released);
        $this->assertSame(1, (int) Cache::get('blizzard:429-count'));
    }

    public function test_throttle_timeout_releases_job_without_burning_attempts(): void
    {
        // A request that can't get an HTTP slot within the block window bubbles
        // up as BlizzardThrottleTimeoutException — re-queue, don't fail the job.
        $job = $this->fakeJob();

        (new BlizzardRateLimiter)->handle($job, function () {
            throw new BlizzardThrottleTimeoutException('no slot');
        });

        $this->assertSame([10], $job->released);
    }
}
