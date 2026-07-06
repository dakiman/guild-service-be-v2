<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Support;

use App\Blizzard\Exceptions\BlizzardThrottleTimeoutException;
use App\Blizzard\Support\BlizzardHttpThrottle;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * Request-level throttle (2026-07-06 follow-up): the old job-level throttle
 * counted jobs, not HTTP calls, so a Full sync (~15-25 calls) blew Blizzard's
 * budget and flapped the circuit breaker. Every request to api.blizzard.com
 * must acquire a slot here instead.
 */
class BlizzardHttpThrottleTest extends TestCase
{
    public function test_disabled_when_requests_per_second_is_zero(): void
    {
        config(['blizzard.rate_limit.requests_per_second' => 0]);

        Redis::shouldReceive('throttle')->never();

        (new BlizzardHttpThrottle)->acquire();
    }

    public function test_acquires_one_slot_per_request(): void
    {
        config([
            'blizzard.rate_limit.requests_per_second' => 8,
            'blizzard.rate_limit.block_seconds' => 30,
        ]);

        $limiter = Mockery::mock();
        $limiter->shouldReceive('allow')->with(8)->times(3)->andReturnSelf();
        $limiter->shouldReceive('every')->with(1)->times(3)->andReturnSelf();
        $limiter->shouldReceive('block')->with(30)->times(3)->andReturnSelf();
        $limiter->shouldReceive('then')->times(3)->andReturnUsing(fn ($cb) => $cb());

        Redis::shouldReceive('throttle')->with(BlizzardHttpThrottle::KEY)->times(3)->andReturn($limiter);

        (new BlizzardHttpThrottle)->acquire(3);
    }

    public function test_timeout_surfaces_as_domain_exception(): void
    {
        config(['blizzard.rate_limit.requests_per_second' => 8]);

        $limiter = Mockery::mock();
        $limiter->shouldReceive('allow')->andReturnSelf();
        $limiter->shouldReceive('every')->andReturnSelf();
        $limiter->shouldReceive('block')->andReturnSelf();
        $limiter->shouldReceive('then')->andThrow(new LimiterTimeoutException);

        Redis::shouldReceive('throttle')->andReturn($limiter);

        $this->expectException(BlizzardThrottleTimeoutException::class);

        (new BlizzardHttpThrottle)->acquire();
    }
}
