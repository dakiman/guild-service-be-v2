<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientCrawlTest extends TestCase
{
    public function test_get_character_mythic_plus_runs_returns_profile_data(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        Http::fake([
            'raider.io/api/v1/characters/profile*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);
        $result = $client->getCharacterMythicPlusRuns('eu', 'the-maelstrom', 'testchar');

        $this->assertArrayHasKey('mythic_plus_recent_runs', $result);
        $this->assertArrayHasKey('mythic_plus_best_runs', $result);
        $this->assertArrayHasKey('mythic_plus_highest_level_runs', $result);
        $this->assertCount(2, $result['mythic_plus_recent_runs']);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, 'characters/profile')
                && str_contains($url, 'region=eu')
                && str_contains($url, 'realm=the-maelstrom')
                && str_contains($url, 'name=testchar')
                && str_contains($url, 'mythic_plus_recent_runs')
                && str_contains($url, 'mythic_plus_best_runs')
                && str_contains($url, 'mythic_plus_highest_level_runs');
        });
    }

    public function test_get_run_details_returns_run_data(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );

        Http::fake([
            'raider.io/api/v1/mythic-plus/run-details*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);
        $result = $client->getRunDetails('season-mn-1', 21957615);

        $this->assertSame(21957615, $result['keystone_run_id']);
        $this->assertCount(5, $result['roster']);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, 'mythic-plus/run-details')
                && str_contains($url, 'season=season-mn-1')
                && str_contains($url, 'id=21957615');
        });
    }
}
