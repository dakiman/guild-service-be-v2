<?php

declare(strict_types=1);

namespace App\Blizzard\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

class BlizzardRateLimiter
{
    public function handle(object $job, Closure $next): void
    {
        Redis::throttle('blizzard-api')
            ->allow((int) config('blizzard.rate_limit.per_second', 80))
            ->every(1)
            ->block(30)
            ->then(
                function () use ($job, $next) {
                    $next($job);
                },
                function () use ($job) {
                    $job->release(10);
                },
            );
    }
}
