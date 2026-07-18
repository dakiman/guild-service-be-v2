<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\Exceptions\RaiderIOThrottledException;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * raider.io 429s must become a typed, catchable exception carrying the wait
 * time — not a blocking in-process sleep+retry. Job middleware
 * (RaiderIORateLimiter) turns this into release($retryAfter) so a throttled
 * crawl worker goes back to the pool instead of sleeping.
 */
class RaiderIOClientThrottleTest extends TestCase
{
    public function test_429_with_retry_after_throws_throttled_exception_with_no_blocking_retry(): void
    {
        Http::fake(fn () => Http::response('', 429, ['Retry-After' => '30']));

        $client = app(RaiderIOClient::class);

        try {
            iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
            $this->fail('Expected RaiderIOThrottledException');
        } catch (RaiderIOThrottledException $e) {
            $this->assertSame(30, $e->retryAfter);
        }

        Http::assertSentCount(1);
    }

    public function test_429_without_retry_after_header_defaults_to_60(): void
    {
        Http::fake(fn () => Http::response('', 429));

        $client = app(RaiderIOClient::class);

        try {
            iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
            $this->fail('Expected RaiderIOThrottledException');
        } catch (RaiderIOThrottledException $e) {
            $this->assertSame(60, $e->retryAfter);
        }
    }

    public function test_429_retry_after_is_capped_at_90(): void
    {
        Http::fake(fn () => Http::response('', 429, ['Retry-After' => '600']));

        $client = app(RaiderIOClient::class);

        try {
            iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
            $this->fail('Expected RaiderIOThrottledException');
        } catch (RaiderIOThrottledException $e) {
            $this->assertSame(90, $e->retryAfter);
        }
    }
}
