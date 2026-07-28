<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOSeedDungeonLaddersTest extends TestCase
{
    use RefreshDatabase;

    public function test_season_dungeon_slugs_reads_static_data_for_the_given_season(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/static-data-expansion-11.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);

        $slugs = app(RaiderIOClient::class)->seasonDungeonSlugs(11, 'season-mn-1');

        $this->assertSame(['maisara-caverns', 'pit-of-saron'], $slugs);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'mythic-plus/static-data')
            && str_contains($request->url(), 'expansion_id=11'));
    }

    public function test_season_dungeon_slugs_returns_empty_for_unknown_season(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/static-data-expansion-11.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);

        $this->assertSame([], app(RaiderIOClient::class)->seasonDungeonSlugs(11, 'season-xx-9'));
    }
}
