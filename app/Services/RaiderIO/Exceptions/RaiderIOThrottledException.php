<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Exceptions;

/**
 * raider.io returned 429, or a Redis throttle slot couldn't be acquired in
 * time. RaiderIORateLimiter job middleware catches this and releases the job
 * (non-blocking) instead of sleeping in-process or burning an attempt.
 */
class RaiderIOThrottledException extends RaiderIOException
{
    public function __construct(
        public readonly int $retryAfter,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "raider.io: throttled, retry after {$retryAfter}s");
    }
}
