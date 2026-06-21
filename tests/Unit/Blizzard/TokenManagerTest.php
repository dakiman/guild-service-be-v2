<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard;

use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P1.5: token lifecycle.
 *  - TTL must honor Blizzard's expires_in (minus a safety buffer), not assume
 *    a flat 24h — a shorter-lived token used to be served until it 401'd.
 *  - refreshToken() must actually fetch even when a token is cached; the old
 *    short-circuit made the twice-daily scheduled refresh a no-op.
 */
class TokenManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_token_ttl_honors_blizzard_expires_in(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $calls = 0;
        Http::fake(['*/oauth/token' => function () use (&$calls) {
            $calls++;

            return Http::response(['access_token' => "tok{$calls}", 'expires_in' => 600], 200);
        }]);

        $manager = app(TokenManagerInterface::class);

        $this->assertSame('tok1', $manager->getToken('eu'));

        // Within expires_in - 300 = 300s the token is still cached.
        Carbon::setTestNow('2026-06-01 12:04:59');
        $this->assertSame('tok1', $manager->getToken('eu'));

        // Past the honored TTL a fresh token must be fetched.
        Carbon::setTestNow('2026-06-01 12:05:01');
        $this->assertSame('tok2', $manager->getToken('eu'));
    }

    public function test_refresh_token_fetches_new_token_even_when_one_is_cached(): void
    {
        $calls = 0;
        Http::fake(['*/oauth/token' => function () use (&$calls) {
            $calls++;

            return Http::response(['access_token' => "tok{$calls}", 'expires_in' => 86400], 200);
        }]);

        $manager = app(TokenManagerInterface::class);

        $this->assertSame('tok1', $manager->getToken('eu'));      // caches tok1
        $this->assertSame('tok2', $manager->refreshToken('eu'));  // forced: must fetch a NEW token
        $this->assertSame('tok2', $manager->getToken('eu'));      // cache now holds tok2
    }
}
