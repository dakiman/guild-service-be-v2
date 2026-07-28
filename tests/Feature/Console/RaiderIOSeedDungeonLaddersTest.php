<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\SeededRun;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    protected function fakeLadders(int $staticDataStatus = 200): void
    {
        $global = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        $dungeon = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu-dungeon.json')), true);
        $staticData = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/static-data-expansion-11.json')), true);

        Http::fake(function ($request) use ($global, $dungeon, $staticData, $staticDataStatus) {
            $url = $request->url();
            if (str_contains($url, 'static-data')) {
                return Http::response($staticDataStatus === 200 ? $staticData : [], $staticDataStatus);
            }
            if (str_contains($url, 'dungeon=')) {
                return Http::response($dungeon, 200);
            }

            return Http::response($global, 200);
        });
    }

    public function test_dungeon_ladders_seed_new_members_and_dedupe_across_ladders(): void
    {
        Bus::fake();
        $this->fakeLadders();
        config()->set('raiderio.season', 'season-mn-1');
        config()->set('raiderio.phase.runs_pages_per_dungeon', 1);

        $this->artisan('raiderio:seed', ['--phase' => 'runs', '--limit' => 1, '--regions' => 'eu'])
            ->assertSuccessful();

        // Global ladder: 3 runs × 5 = 15 members. Both dungeon ladders serve the
        // same fixture run (id 2001): first yields 4 new members (Alice deduped),
        // second yields 0 (all five already dispatched this invocation).
        Bus::assertDispatched(SyncCharacterData::class, 19);

        // Run 2001 enters the ledger once despite appearing on both dungeon ladders.
        $this->assertSame(1, SeededRun::where('keystone_run_id', 2001)->count());
    }

    public function test_char_dispatch_cap_stops_the_region(): void
    {
        Bus::fake();
        $this->fakeLadders();
        config()->set('raiderio.phase.runs_pages_per_dungeon', 1);
        config()->set('raiderio.phase.max_char_dispatches_per_region', 7);

        $this->artisan('raiderio:seed', ['--phase' => 'runs', '--limit' => 1, '--regions' => 'eu'])
            ->assertSuccessful();

        Bus::assertDispatched(SyncCharacterData::class, 7);
    }

    public function test_static_data_failure_skips_dungeon_ladders_but_global_still_seeds(): void
    {
        Bus::fake();
        $this->fakeLadders(staticDataStatus: 500);
        config()->set('raiderio.phase.runs_pages_per_dungeon', 1);

        $this->artisan('raiderio:seed', ['--phase' => 'runs', '--limit' => 1, '--regions' => 'eu'])
            ->assertSuccessful();

        // Global ladder only — static-data failure must not kill the phase.
        Bus::assertDispatched(SyncCharacterData::class, 15);
    }
}
