<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\RaiderIOClient;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P1.9: Response::header() returns '' (not null) for a missing header, so the
 * old `(int) ($header ?? 60)` produced 0 — an immediate re-send that hit a
 * second 429 and threw. The wait must default to 60s and be capped so it can't
 * exceed the Horizon job timeout.
 */
class RaiderIOClientRetryAfterTest extends TestCase
{
    private function retryAfter(Response $response): int
    {
        $client = app(RaiderIOClient::class);
        $method = new ReflectionMethod($client, 'retryAfterSeconds');

        return $method->invoke($client, $response);
    }

    public function test_defaults_to_60_when_header_absent(): void
    {
        $this->assertSame(60, $this->retryAfter(new Response(new GuzzleResponse(429))));
    }

    public function test_uses_header_value_when_present(): void
    {
        $this->assertSame(30, $this->retryAfter(new Response(new GuzzleResponse(429, ['Retry-After' => '30']))));
    }

    public function test_caps_wait_at_90_seconds(): void
    {
        $this->assertSame(90, $this->retryAfter(new Response(new GuzzleResponse(429, ['Retry-After' => '600']))));
    }
}
