<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\GameDataSeason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrentSeasonResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function seedSeason(int $id = 17): void
    {
        GameDataSeason::create([
            'id' => $id,
            'slug' => "season-test-{$id}",
            'name' => "Test Season {$id}",
            'raiderio_tier_slug' => 'tier-test',
            'raiderio_expansion_id' => 11,
            'is_current' => true,
        ]);
    }

    public function test_env_override_wins_over_registry(): void
    {
        config(['blizzard.mythic_plus.season_override' => 99]);
        $this->seedSeason(17);
        Http::fake(); // any HTTP call would throw a fake-miss below

        $client = app(BlizzardGameDataClient::class);

        $this->assertSame(99, $client->getCurrentMythicPlusSeason());
        Http::assertNothingSent();
    }

    public function test_registry_resolves_without_any_http_call(): void
    {
        config(['blizzard.mythic_plus.season_override' => null]);
        $this->seedSeason(17);
        Http::fake();

        $client = app(BlizzardGameDataClient::class);

        $this->assertSame(17, $client->getCurrentMythicPlusSeason());
        Http::assertNothingSent();
    }

    public function test_empty_registry_falls_back_to_blizzard_index(): void
    {
        config(['blizzard.mythic_plus.season_override' => null]);
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            '*.api.blizzard.com/data/wow/mythic-keystone/season/index*' => Http::response([
                'seasons' => [['id' => 15], ['id' => 17]],
                'current_season' => ['id' => 17],
            ]),
        ]);

        $client = app(BlizzardGameDataClient::class);

        $this->assertSame(17, $client->getCurrentMythicPlusSeason());
    }

    public function test_season_index_getter_returns_raw_payload(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            '*.api.blizzard.com/data/wow/mythic-keystone/season/index*' => Http::response([
                'seasons' => [['id' => 15], ['id' => 17]],
                'current_season' => ['id' => 17],
            ]),
        ]);

        $index = app(BlizzardGameDataClient::class)->getMythicPlusSeasonIndex();

        $this->assertSame([15, 17], array_column($index['seasons'], 'id'));
        $this->assertSame(17, $index['current_season']['id']);
    }
}
