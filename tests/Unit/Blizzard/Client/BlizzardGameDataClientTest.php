<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlizzardGameDataClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function client(): BlizzardGameDataClient
    {
        $tokenManager = $this->createMock(TokenManagerInterface::class);
        $tokenManager->method('getToken')->willReturn('fake-token');

        // Region is a readonly constructor param on the parent BlizzardClient;
        // there is no setter. See BlizzardClient.php:16.
        return new BlizzardGameDataClient($tokenManager, 'us');
    }

    public function test_get_faction_index_returns_response_in_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/index?*' => Http::response([
                'factions' => [
                    ['id' => 2510, 'name' => 'Valdrakken Accord'],
                    ['id' => 2570, 'name' => 'Council of Dornogal'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getFactionIndex();

        $this->assertSame(2510, $result['factions'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'reputation-faction/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_faction_index_caches_within_ttl(): void
    {
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['factions' => []], 200);
        });

        $client = $this->client();
        $client->getFactionIndex();
        $client->getFactionIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_faction_index_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/index?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getFactionIndex());
    }

    public function test_get_faction_returns_response_in_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/2510?*' => Http::response([
                'id' => 2510,
                'name' => 'Valdrakken Accord',
                'category' => ['id' => 1245],
            ], 200),
        ]);

        $result = $this->client()->getFaction(2510);

        $this->assertSame(2510, $result['id']);
        $this->assertSame('Valdrakken Accord', $result['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'reputation-faction/2510')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_faction_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getFaction(99999));
    }

    public function test_get_title_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/index?*' => Http::response([
                'titles' => [
                    ['id' => 1, 'name' => 'Private'],
                    ['id' => 414, 'name' => '{name}, the Bear'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getTitleIndex();

        $this->assertSame(1, $result['titles'][0]['id']);
        $this->assertSame(414, $result['titles'][1]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/data/wow/title/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_title_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['titles' => []], 200);
        });

        $client = $this->client();
        $client->getTitleIndex();
        $client->getTitleIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_title_index_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/index?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getTitleIndex());
    }

    public function test_get_title_returns_gender_name_payload(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/414?*' => Http::response([
                'id' => 414,
                'name' => '{name}, the Bear',
                'gender_name' => [
                    'male' => '{name}, Lord of the Bears',
                    'female' => '{name}, Lady of the Bears',
                ],
            ], 200),
        ]);

        $result = $this->client()->getTitle(414);

        $this->assertSame(414, $result['id']);
        $this->assertSame('{name}, Lord of the Bears', $result['gender_name']['male']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/data/wow/title/414')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_title_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getTitle(99999));
    }
}
