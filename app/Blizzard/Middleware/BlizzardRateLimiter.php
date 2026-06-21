<?php

declare(strict_types=1);

namespace App\Blizzard\Middleware;

use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class BlizzardRateLimiter
{
    public function handle(object $job, Closure $next): void
    {
        Redis::throttle('blizzard-api')
            ->allow((int) config('blizzard.rate_limit.per_second', 10))
            ->every(1)
            ->block(30)
            ->then(
                function () use ($job, $next) {
                    try {
                        $next($job);
                    } catch (RequestException $e) {
                        if ($e->response?->status() === 429) {
                            $retryAfter = (int) ($e->response->header('Retry-After') ?: 10);
                            $job->release($retryAfter);

                            $this->recordRateLimitHit();

                            return;
                        }
                        throw $e;
                    }
                },
                function () use ($job) {
                    $job->release(10);
                },
            );
    }

    private function recordRateLimitHit(): void
    {
        $key = 'blizzard:429-count';
        $threshold = (int) config('blizzard.rate_limit.circuit_breaker.threshold', 10);
        $window = (int) config('blizzard.rate_limit.circuit_breaker.window', 120);
        $cooldown = (int) config('blizzard.rate_limit.circuit_breaker.cooldown', 60);

        // add() seeds the key with a TTL atomically *before* incrementing, so a
        // worker that dies mid-method can't leave a TTL-less key that
        // accumulates 429 counts forever. (P1.10)
        Cache::add($key, 0, $window);
        $count = (int) Cache::increment($key);

        if ($count >= $threshold) {
            Cache::put('blizzard:unhealthy', true, $cooldown);
            Cache::forget($key);
        }
    }
}
