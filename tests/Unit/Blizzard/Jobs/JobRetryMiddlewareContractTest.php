<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Jobs\SyncUserCharacters;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Models\Guild;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1.10: Blizzard jobs must (a) bound retries by time (retryUntil) rather than
 * a fixed $tries that every middleware release() burns — a short outage would
 * otherwise exhaust the 15 attempts and permanently fail the job — and (b) run
 * the health check before the rate limiter, so an open circuit isn't paid for
 * with a throttle slot first.
 */
class JobRetryMiddlewareContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_guild_data_retries_are_time_bounded(): void
    {
        $this->assertGreaterThan(now()->addHours(5), (new SyncGuildData('eu', 'tarren-mill', 'echo'))->retryUntil());
    }

    public function test_sync_guild_roster_retries_are_time_bounded(): void
    {
        $this->assertGreaterThan(now()->addHours(5), (new SyncGuildRoster(Guild::factory()->create()))->retryUntil());
    }

    public function test_sync_user_characters_retries_are_time_bounded(): void
    {
        $this->assertGreaterThan(now()->addHours(5), (new SyncUserCharacters(User::factory()->create(), 'eu', 'tok'))->retryUntil());
    }

    public function test_all_jobs_run_health_check_before_rate_limiter(): void
    {
        $jobs = [
            new SyncCharacterData('eu', 'tarren-mill', 'x'),
            new SyncGuildData('eu', 'tarren-mill', 'echo'),
            new SyncGuildRoster(Guild::factory()->create()),
            new SyncUserCharacters(User::factory()->create(), 'eu', 'tok'),
        ];

        foreach ($jobs as $job) {
            $mw = $job->middleware();
            $this->assertInstanceOf(BlizzardHealthCheck::class, $mw[0], $job::class.': health check must run first');
            $this->assertInstanceOf(BlizzardRateLimiter::class, $mw[1], $job::class.': rate limiter must run second');
        }
    }
}
