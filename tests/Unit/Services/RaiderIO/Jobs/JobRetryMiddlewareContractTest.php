<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO\Jobs;

use App\Services\RaiderIO\Jobs\CrawlCharacterRuns;
use App\Services\RaiderIO\Jobs\FetchRunRoster;
use App\Services\RaiderIO\Middleware\RaiderIORateLimiter;
use Tests\TestCase;

/**
 * release() still increments attempts, so a job that can be released on a
 * 429 needs time-bound retries (retryUntil), not a fixed $tries — a handful
 * of throttle releases plus normal retries would otherwise exhaust the
 * attempts budget and MaxAttemptsExceededException reaps the job. Mirrors
 * the Blizzard contract (tests/Unit/Blizzard/Jobs/JobRetryMiddlewareContractTest.php).
 */
class JobRetryMiddlewareContractTest extends TestCase
{
    public function test_crawl_character_runs_retries_are_time_bounded(): void
    {
        $job = new CrawlCharacterRuns('eu', 'tarren-mill', 'x', 13);

        $this->assertGreaterThan(now()->addHours(5), $job->retryUntil());
        $this->assertFalse(property_exists($job, 'tries'), 'CrawlCharacterRuns must not set a fixed $tries');
    }

    public function test_fetch_run_roster_retries_are_time_bounded(): void
    {
        $job = new FetchRunRoster(1, 'season-mn-1', 'eu');

        $this->assertGreaterThan(now()->addHours(5), $job->retryUntil());
        $this->assertFalse(property_exists($job, 'tries'), 'FetchRunRoster must not set a fixed $tries');
    }

    public function test_crawl_character_runs_middleware_includes_rate_limiter(): void
    {
        $job = new CrawlCharacterRuns('eu', 'tarren-mill', 'x', 13);

        $this->assertInstanceOf(RaiderIORateLimiter::class, $job->middleware()[0]);
    }

    public function test_fetch_run_roster_middleware_includes_rate_limiter(): void
    {
        $job = new FetchRunRoster(1, 'season-mn-1', 'eu');

        $this->assertInstanceOf(RaiderIORateLimiter::class, $job->middleware()[0]);
    }
}
