<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\RaiderIOClient;
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

    public function test_top_guilds_retries_once_after_429_with_retry_after(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);

        $calls = 0;
        Http::fake(function () use ($fixture, &$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response('', 429, ['Retry-After' => '0']);
            }

            return Http::response($fixture, 200);
        });

        $client = app(RaiderIOClient::class);

        $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        $this->assertCount(3, $refs);
        $this->assertSame(2, $calls);
    }

    public function test_top_guilds_throws_after_second_429(): void
    {
        Http::fake(fn () => Http::response('', 429, ['Retry-After' => '0']));

        $client = app(RaiderIOClient::class);

        $this->expectException(RaiderIOException::class);
        iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
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
