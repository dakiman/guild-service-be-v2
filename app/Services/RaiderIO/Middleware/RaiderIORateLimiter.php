<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Middleware;

use App\Services\RaiderIO\Exceptions\RaiderIOThrottledException;
use Closure;

/**
 * Mirrors App\Blizzard\Middleware\BlizzardRateLimiter minus the circuit
 * breaker: a 429 (or a Redis throttle-slot timeout, surfaced as the same
 * typed exception) releases the job instead of blocking the crawl worker or
 * burning an attempts budget. A plain RaiderIOException propagates and is
 * counted against $maxExceptions like any other failure.
 */
class RaiderIORateLimiter
{
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (RaiderIOThrottledException $e) {
            $job->release($e->retryAfter);
        }
    }
}
