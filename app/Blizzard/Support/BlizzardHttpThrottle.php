<?php

declare(strict_types=1);

namespace App\Blizzard\Support;

use App\Blizzard\Exceptions\BlizzardThrottleTimeoutException;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Redis;

/**
 * Request-level Blizzard API throttle. The old job-level throttle counted
 * jobs, not HTTP calls — a Full sync makes ~15-25 calls, so 10 jobs/s could
 * burst hundreds of req/s past Blizzard's budget and flap the circuit
 * breaker (2026-07-06). Every request to api.blizzard.com acquires a slot
 * here, via the global request middleware in BlizzardServiceProvider.
 *
 * `requests_per_second` <= 0 disables the throttle entirely (tests).
 */
class BlizzardHttpThrottle
{
    public const KEY = 'blizzard-api-http';

    public function acquire(int $slots = 1): void
    {
        $perSecond = (int) config('blizzard.rate_limit.requests_per_second', 8);

        if ($perSecond <= 0) {
            return;
        }

        $block = (int) config('blizzard.rate_limit.block_seconds', 30);

        for ($i = 0; $i < $slots; $i++) {
            try {
                Redis::throttle(self::KEY)
                    ->allow($perSecond)
                    ->every(1)
                    ->block($block)
                    ->then(static fn () => null);
            } catch (LimiterTimeoutException $e) {
                throw new BlizzardThrottleTimeoutException(
                    "no Blizzard HTTP slot within {$block}s",
                    previous: $e,
                );
            }
        }
    }
}
