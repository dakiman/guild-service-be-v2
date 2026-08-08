<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\GameDataClientFactory;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlizzardLadderEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function client(string $region = 'eu'): BlizzardGameDataClient
    {
        $tokenManager = $this->createMock(TokenManagerInterface::class);
        $tokenManager->method('getToken')->willReturn('fake-token');

        return new BlizzardGameDataClient($tokenManager, $region);
    }

    public function test_period_index_uses_dynamic_namespace(): void
    {
        Http::fake([
            'eu.api.blizzard.com/data/wow/mythic-keystone/period/index*' => Http::response([
                'periods' => [['id' => 1001], ['id' => 1002]],
                'current_period' => ['id' => 1002],
            ]),
        ]);

        $index = $this->client()->getMythicKeystonePeriodIndex();

        $this->assertSame(1002, $index['current_period']['id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'namespace=dynamic-eu'));
    }

    public function test_leaderboard_returns_null_on_404_and_is_uncached(): void
    {
        Http::fake([
            'eu.api.blizzard.com/data/wow/connected-realm/509/mythic-leaderboard/504/period/1002*' => Http::sequence()
                ->push(['detail' => 'Not Found'], 404)
                ->push(['leading_groups' => []], 200),
        ]);

        $client = $this->client();
        $this->assertNull($client->getMythicLeaderboard(509, 504, 1002));
        $this->assertSame(['leading_groups' => []], $client->getMythicLeaderboard(509, 504, 1002));
    }

    public function test_connected_realm_index_and_detail(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/connected-realm/index*' => Http::response([
                'connected_realms' => [['href' => 'https://us.api.blizzard.com/data/wow/connected-realm/11?namespace=dynamic-us']],
            ]),
            'us.api.blizzard.com/data/wow/connected-realm/11*' => Http::response([
                'id' => 11,
                'realms' => [['slug' => 'tichondrius'], ['slug' => 'proudmoore']],
            ]),
        ]);

        $client = $this->client('us');
        $this->assertCount(1, $client->getConnectedRealmIndex()['connected_realms']);
        $this->assertSame(11, $client->getConnectedRealm(11)['id']);
    }

    public function test_factory_builds_region_scoped_client(): void
    {
        $factory = app(GameDataClientFactory::class);
        $this->assertInstanceOf(BlizzardGameDataClient::class, $factory->forRegion('us'));
    }
}
