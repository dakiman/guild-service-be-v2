<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\Exceptions\RaiderIOThrottledException;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientTest extends TestCase
{
    public function test_top_guilds_yields_guild_refs_from_response(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);
        Http::fake([
            'raider.io/api/v1/raiding/raid-rankings*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);

        $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        $this->assertCount(3, $refs);
        $this->assertInstanceOf(SeedGuildRef::class, $refs[0]);
        $this->assertSame('eu', $refs[0]->region);
        $this->assertSame('tarren-mill', $refs[0]->realmSlug);
        // Names are canonicalized via BlizzardIdentity::realm() (Str::slug) at the
        // client boundary so seeder dispatches match the form CharacterController/
        // GuildController use for user-initiated lookups.
        $this->assertSame('echo', $refs[0]->name);
        $this->assertSame('method', $refs[1]->name);
        $this->assertSame('fatsharkyes', $refs[2]->name);
    }

    public function test_top_guilds_paginates_when_limit_exceeds_page_size(): void
    {
        $page0 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu-page-1-full.json')), true);
        $page1 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu-page-2.json')), true);

        Http::fake(function ($request) use ($page0, $page1) {
            // raider.io page param starts at 0
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $q);
            $page = (int) ($q['page'] ?? 0);

            return Http::response($page === 0 ? $page0 : $page1, 200);
        });

        $client = app(RaiderIOClient::class);

        $refs = iterator_to_array($client->topGuilds('eu', 23), preserve_keys: false);

        $this->assertCount(23, $refs);
        Http::assertSentCount(2);
    }

    /**
     * P8: a 429 must throw immediately as a typed, catchable exception — not
     * block the worker with an in-process sleep+retry. Job middleware
     * (RaiderIORateLimiter) is what turns this into a release().
     */
    public function test_top_guilds_throws_throttled_exception_on_429_without_blocking_retry(): void
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

    public function test_top_guilds_retries_on_5xx_up_to_three_times(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);

        $calls = 0;
        Http::fake(function () use ($fixture, &$calls) {
            $calls++;

            return $calls < 3
                ? Http::response('', 502)
                : Http::response($fixture, 200);
        });

        $client = app(RaiderIOClient::class);

        $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        $this->assertCount(3, $refs);
        $this->assertSame(3, $calls);
    }

    public function test_top_guilds_throws_after_3_5xx_failures(): void
    {
        Http::fake(fn () => Http::response('', 502));

        $client = app(RaiderIOClient::class);

        $this->expectException(RaiderIOException::class);
        iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
    }

    /**
     * A cURL timeout / connection refusal surfaces as ConnectionException, not a
     * 5xx response. It must be retried on the same budget as a 5xx instead of
     * escaping the client and aborting the whole seed run.
     */
    public function test_top_guilds_retries_on_connection_exception(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);

        $calls = 0;
        Http::fake(function () use ($fixture, &$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 15002 milliseconds');
            }

            return Http::response($fixture, 200);
        });

        $client = app(RaiderIOClient::class);

        $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        $this->assertCount(3, $refs);
        $this->assertSame(2, $calls);
    }

    public function test_top_guilds_throws_raiderio_exception_after_exhausting_connection_retries(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('cURL error 28: Operation timed out after 15002 milliseconds');
        });

        $client = app(RaiderIOClient::class);

        try {
            iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
            $this->fail('Expected RaiderIOException');
        } catch (ConnectionException $e) {
            $this->fail('ConnectionException escaped the client: '.$e->getMessage());
        } catch (RaiderIOException $e) {
            $this->assertStringContainsString('/raiding/raid-rankings', $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }

        // 1 initial attempt + 3 retries on the shared 5xx/connection budget.
        $this->assertSame(4, $calls);
    }

    public function test_top_guilds_canonicalizes_mixed_case_names(): void
    {
        Http::fake(['raider.io/*' => Http::response([
            'raidRankings' => [
                [
                    'rank' => 1,
                    'guild' => [
                        'name' => 'FatSharkYes',  // mixed case
                        'realm' => ['slug' => 'tarren-mill'],
                        'region' => ['slug' => 'eu'],
                    ],
                ],
                [
                    'rank' => 2,
                    'guild' => [
                        'name' => 'Méthod',  // UTF-8 + mixed case
                        'realm' => ['slug' => 'TWISTING-NETHER'],  // upper realm slug
                        'region' => ['slug' => 'eu'],
                    ],
                ],
            ],
        ], 200)]);

        $client = app(RaiderIOClient::class);
        $refs = iterator_to_array($client->topGuilds('eu', 2), preserve_keys: false);

        $this->assertSame('fatsharkyes', $refs[0]->name);
        $this->assertSame('tarren-mill', $refs[0]->realmSlug);
        // Str::slug strips accents and lowercases.
        $this->assertSame('method', $refs[1]->name);
        $this->assertSame('twisting-nether', $refs[1]->realmSlug);
    }

    public function test_access_key_is_appended_when_configured(): void
    {
        config()->set('raiderio.access_key', 'secret-token-xyz');

        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);

        $client = app(RaiderIOClient::class);
        iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        Http::assertSent(function ($request) {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $q);

            return ($q['access_key'] ?? null) === 'secret-token-xyz';
        });
    }

    public function test_access_key_is_omitted_when_not_configured(): void
    {
        config()->set('raiderio.access_key', null);

        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);

        $client = app(RaiderIOClient::class);
        iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        Http::assertSent(function ($request) {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $q);

            return ! array_key_exists('access_key', $q);
        });
    }
}
