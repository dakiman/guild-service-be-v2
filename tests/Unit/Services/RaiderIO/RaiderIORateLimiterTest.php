<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\Exceptions\RaiderIOThrottledException;
use App\Services\RaiderIO\Middleware\RaiderIORateLimiter;
use Tests\TestCase;

/**
 * Mirrors BlizzardRateLimiter minus the circuit breaker: a throttled
 * exception releases the job (no attempts burned); anything else propagates.
 */
class RaiderIORateLimiterTest extends TestCase
{
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

    public function test_passes_job_through_without_release(): void
    {
        $job = $this->fakeJob();
        $ran = false;

        (new RaiderIORateLimiter)->handle($job, function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertSame([], $job->released);
    }

    public function test_throttled_exception_releases_job_with_retry_after(): void
    {
        $job = $this->fakeJob();

        (new RaiderIORateLimiter)->handle($job, function () {
            throw new RaiderIOThrottledException(30);
        });

        $this->assertSame([30], $job->released);
    }

    public function test_plain_raiderio_exception_propagates(): void
    {
        $job = $this->fakeJob();

        $this->expectException(RaiderIOException::class);

        (new RaiderIORateLimiter)->handle($job, function () {
            throw new RaiderIOException('boom');
        });
    }
}
