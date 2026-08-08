<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\GameDataClientFactory;
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncLadderGameDataTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFactory(array $methods): void
    {
        $client = $this->createMock(BlizzardGameDataClient::class);
        foreach ($methods as $method => $return) {
            is_callable($return)
                ? $client->method($method)->willReturnCallback($return)
                : $client->method($method)->willReturn($return);
        }

        $factory = $this->createMock(GameDataClientFactory::class);
        $factory->method('forRegion')->willReturn($client);
        $this->app->instance(GameDataClientFactory::class, $factory);
    }

    public function test_syncs_recent_periods_per_region(): void
    {
        config(['blizzard.mplus_leaderboard.regions' => ['eu']]);
        $this->fakeFactory([
            'getMythicKeystonePeriodIndex' => ['periods' => [['id' => 1001], ['id' => 1002]], 'current_period' => ['id' => 1002]],
            'getMythicKeystonePeriod' => fn (int $id): array => [
                'id' => $id,
                'start_timestamp' => 1754000000000,
                'end_timestamp' => 1754604800000,
            ],
        ]);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'periods'])->assertExitCode(0);

        $this->assertSame(2, GameDataPeriod::where('region', 'eu')->count());
        $this->assertNotNull(GameDataPeriod::where('period_id', 1002)->first()->start_at);
    }

    public function test_syncs_connected_realms_parsing_ids_from_hrefs(): void
    {
        config(['blizzard.mplus_leaderboard.regions' => ['eu']]);
        $this->fakeFactory([
            'getConnectedRealmIndex' => ['connected_realms' => [
                ['href' => 'https://eu.api.blizzard.com/data/wow/connected-realm/509?namespace=dynamic-eu'],
                ['href' => 'https://eu.api.blizzard.com/data/wow/connected-realm/1305?namespace=dynamic-eu'],
            ]],
            'getConnectedRealm' => fn (int $id): array => ['id' => $id, 'realms' => [['slug' => "realm-{$id}"]]],
        ]);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'connected-realms'])->assertExitCode(0);

        $this->assertSame(2, GameDataConnectedRealm::where('region', 'eu')->count());
        $this->assertSame(['realm-509'], GameDataConnectedRealm::where('connected_realm_id', 509)->first()->realm_slugs);
    }
}
