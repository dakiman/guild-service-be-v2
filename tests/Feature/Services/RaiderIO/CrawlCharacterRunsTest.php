<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RaiderIO;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Jobs\CrawlCharacterRuns;
use App\Services\RaiderIO\Jobs\FetchRunRoster;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RunTeamPersister;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlCharacterRunsTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(string $region = 'eu', string $realm = 'the-maelstrom', string $name = 'testchar'): void
    {
        $job = new CrawlCharacterRuns($region, $realm, $name, 13);
        $job->handle(
            app(RaiderIOClient::class),
            new RaiderIOMythicPlusMapper,
            app(RunTeamPersister::class),
        );
    }

    private function fakeProfileResponse(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
    }

    public function test_creates_dungeon_runs_from_raiderio_profile(): void
    {
        $this->fakeProfileResponse();
        Queue::fake([FetchRunRoster::class]);

        $this->runJob();

        $this->assertSame(3, DungeonRun::count());
    }

    public function test_sets_raiderio_columns_on_dungeon_runs(): void
    {
        $this->fakeProfileResponse();
        Queue::fake([FetchRunRoster::class]);

        $this->runJob();

        $seat = DungeonRun::where('keystone_run_id', 21957615)->first();
        $this->assertNotNull($seat);
        $this->assertSame(239, $seat->dungeon_id);
        $this->assertSame(16, $seat->keystone_level);
        $this->assertSame('429.2', $seat->raiderio_score);
        $this->assertStringContainsString('21957615', $seat->raiderio_url);
    }

    public function test_adds_queried_character_as_member(): void
    {
        $this->fakeProfileResponse();
        Queue::fake([FetchRunRoster::class]);

        $this->runJob();

        $seat = DungeonRun::where('keystone_run_id', 21957615)->first();
        $members = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $seat->id)
            ->get();

        $this->assertSame(1, $members->count());
        $this->assertSame('testchar', $members->first()->character_name);
        $this->assertSame('the-maelstrom', $members->first()->character_realm);
        $this->assertSame('eu', $members->first()->character_region);
    }

    public function test_dispatches_fetch_run_roster_for_new_runs(): void
    {
        $this->fakeProfileResponse();
        Queue::fake([FetchRunRoster::class]);

        $this->runJob();

        Queue::assertPushed(FetchRunRoster::class, 3);
    }

    public function test_does_not_dispatch_roster_for_runs_with_full_team(): void
    {
        $run = DungeonRun::factory()->create([
            'keystone_run_id' => 21957615,
            'season' => 13,
            'dungeon_id' => 239,
            'completed_timestamp' => Carbon::parse('2026-05-05T18:28:26.000Z')->getTimestampMs(),
        ]);
        for ($i = 0; $i < 5; $i++) {
            DB::table('dungeon_run_members')->insert([
                'dungeon_run_id' => $run->id,
                'character_name' => "player{$i}",
                'character_realm' => 'tarren-mill',
                'character_region' => 'eu',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->fakeProfileResponse();
        Queue::fake([FetchRunRoster::class]);

        $this->runJob();

        // SEAT already has 5 members → only DFC + ROOK get roster dispatches
        Queue::assertPushed(FetchRunRoster::class, 2);
    }

    public function test_deduplicates_across_characters(): void
    {
        $this->fakeProfileResponse();
        Queue::fake([FetchRunRoster::class]);

        $this->runJob('eu', 'the-maelstrom', 'testchar');
        $this->runJob('eu', 'tarren-mill', 'anotherchar');

        $this->assertSame(3, DungeonRun::count());
    }
}
