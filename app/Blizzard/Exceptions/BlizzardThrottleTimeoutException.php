<?php

declare(strict_types=1);

namespace App\Blizzard\Exceptions;

use RuntimeException;

/**
 * Couldn't acquire an HTTP rate-limit slot within the block window.
 * BlizzardRateLimiter middleware catches this and releases the job.
 */
class BlizzardThrottleTimeoutException extends RuntimeException {}
