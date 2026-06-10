<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RaiderIO;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Jobs\FetchRunRoster;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RunTeamPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchRunRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_full_roster_from_run_details(): void
    {
        $run = DungeonRun::factory()->create(['keystone_run_id' => 21957615]);
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/mythic-plus/run-details*' => Http::response($fixture, 200)]);

        $job = new FetchRunRoster(21957615, 'season-mn-1', 'eu');
        $job->handle(
            app(RaiderIOClient::class),
            new RaiderIOMythicPlusMapper,
            app(RunTeamPersister::class),
        );

        $members = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->get();

        $this->assertSame(5, $members->count());
        $names = $members->pluck('character_name')->sort()->values()->all();
        $this->assertSame(['Dps2', 'Dps3', 'Healer', 'Tank', 'Testchar'], $names);
    }

    public function test_sets_spec_and_ilvl_on_members(): void
    {
        $run = DungeonRun::factory()->create(['keystone_run_id' => 21957615]);
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/mythic-plus/run-details*' => Http::response($fixture, 200)]);

        $job = new FetchRunRoster(21957615, 'season-mn-1', 'eu');
        $job->handle(
            app(RaiderIOClient::class),
            new RaiderIOMythicPlusMapper,
            app(RunTeamPersister::class),
        );

        $testchar = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->where('character_name', 'Testchar')
            ->first();

        $this->assertSame(259, $testchar->spec_id);
        $this->assertSame('Assassination', $testchar->spec_name);
        $this->assertSame(489, $testchar->equipped_item_level);
        $this->assertSame('The Maelstrom', $testchar->display_realm);
    }

    public function test_bails_if_run_not_found(): void
    {
        Http::fake(['raider.io/api/v1/mythic-plus/run-details*' => Http::response([], 200)]);

        $job = new FetchRunRoster(99999, 'season-mn-1', 'eu');
        $job->handle(
            app(RaiderIOClient::class),
            new RaiderIOMythicPlusMapper,
            app(RunTeamPersister::class),
        );

        $this->assertSame(0, DB::table('dungeon_run_members')->count());
    }
}
