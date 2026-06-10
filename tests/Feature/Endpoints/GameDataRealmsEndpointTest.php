<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameDataRealmsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->app->bind(TokenManagerInterface::class, fn () => new class implements TokenManagerInterface
        {
            public function getToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }

            public function refreshToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }
        });
    }

    private function fakeRealmIndex(): void
    {
        Http::fake([
            'eu.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [
                    ['id' => 1335, 'name' => 'The Maelstrom', 'slug' => 'the-maelstrom'],
                    ['id' => 1403, 'name' => 'Silvermoon', 'slug' => 'silvermoon'],
                ],
            ], 200),
            'us.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [
                    ['id' => 60, 'name' => 'Stormrage', 'slug' => 'stormrage'],
                    ['id' => 1129, 'name' => 'Frostwolf', 'slug' => 'frostwolf'],
                ],
            ], 200),
            'kr.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [
                    ['id' => 205, 'name' => 'Azshara', 'slug' => 'azshara'],
                ],
            ], 200),
            'tw.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [
                    ['id' => 963, 'name' => 'Bleeding Hollow', 'slug' => 'bleeding-hollow'],
                ],
            ], 200),
        ]);
    }

    public function test_returns_merged_realms_across_all_four_regions(): void
    {
        $this->fakeRealmIndex();

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();
        $response->assertJsonCount(6, 'realms');

        $regions = collect($response->json('realms'))->pluck('region')->unique()->sort()->values()->all();
        $this->assertSame(['eu', 'kr', 'tw', 'us'], $regions);
    }

    public function test_each_realm_carries_slug_region_and_slug_derived_display_name(): void
    {
        $this->fakeRealmIndex();

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();

        $byKey = collect($response->json('realms'))
            ->keyBy(fn ($r) => "{$r['region']}:{$r['slug']}")
            ->all();

        $this->assertSame(
            ['slug' => 'the-maelstrom', 'name' => 'The Maelstrom', 'region' => 'eu'],
            $byKey['eu:the-maelstrom']
        );
        $this->assertSame(
            ['slug' => 'bleeding-hollow', 'name' => 'Bleeding Hollow', 'region' => 'tw'],
            $byKey['tw:bleeding-hollow']
        );
    }

    public function test_response_carries_cache_control_header(): void
    {
        $this->fakeRealmIndex();

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_endpoint_is_public_no_auth(): void
    {
        $this->fakeRealmIndex();

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();
    }

    public function test_response_has_no_outer_data_envelope(): void
    {
        $this->fakeRealmIndex();

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();
        $response->assertJsonStructure(['realms']);
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_one_region_failing_still_returns_other_regions(): void
    {
        // tw fails (500) — pre-existing real-world condition where one region's
        // OAuth credentials are unavailable in dev. Endpoint must degrade
        // gracefully, not 500 the whole autocomplete.
        Http::fake([
            'eu.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [['id' => 1335, 'name' => 'The Maelstrom', 'slug' => 'the-maelstrom']],
            ], 200),
            'us.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [['id' => 60, 'name' => 'Stormrage', 'slug' => 'stormrage']],
            ], 200),
            'kr.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [['id' => 205, 'name' => 'Azshara', 'slug' => 'azshara']],
            ], 200),
            'tw.api.blizzard.com/data/wow/realm/index*' => Http::response('boom', 500),
        ]);

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();
        $response->assertJsonCount(3, 'realms');
        $regions = collect($response->json('realms'))->pluck('region')->unique()->sort()->values()->all();
        $this->assertSame(['eu', 'kr', 'us'], $regions);
        $this->assertNotContains('tw', $regions);
    }

    public function test_collision_realm_appears_once_per_region(): void
    {
        // Frostwolf is faked on both EU (added here) and US (in fakeRealmIndex
        // we only have it on US). Recreate fixtures with EU+US Frostwolf to
        // assert each carries its own region tag.
        Http::fake([
            'eu.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [
                    ['id' => 1408, 'name' => 'Frostwolf', 'slug' => 'frostwolf'],
                ],
            ], 200),
            'us.api.blizzard.com/data/wow/realm/index*' => Http::response([
                'realms' => [
                    ['id' => 1129, 'name' => 'Frostwolf', 'slug' => 'frostwolf'],
                ],
            ], 200),
            'kr.api.blizzard.com/data/wow/realm/index*' => Http::response(['realms' => []], 200),
            'tw.api.blizzard.com/data/wow/realm/index*' => Http::response(['realms' => []], 200),
        ]);

        $response = $this->getJson('/api/v1/game-data/realms');

        $response->assertOk();
        $frostwolfs = collect($response->json('realms'))
            ->where('slug', 'frostwolf')
            ->values()
            ->all();

        $this->assertCount(2, $frostwolfs);
        $regions = collect($frostwolfs)->pluck('region')->sort()->values()->all();
        $this->assertSame(['eu', 'us'], $regions);
    }
}
