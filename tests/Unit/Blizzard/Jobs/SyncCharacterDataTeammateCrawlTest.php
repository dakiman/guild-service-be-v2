<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\DungeonRunMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SyncCharacterDataTeammateCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('blizzard.sync.teammate_crawl_enabled', true);
        Config::set('blizzard.crawl.max_depth', 1);
        Config::set('blizzard.crawl.recent_threshold', 21600);
    }

    private function makeSeedWithRun(): Character
    {
        $seed = Character::factory()->create([
            'name' => 'seedy',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'game_version' => 'retail',
            'mythics_synced_at' => now(),
        ]);

        $run = DungeonRun::factory()->create([
            'season' => 14,
            'dungeon_id' => 1234,
            'completed_timestamp' => 1700000000000,
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run->id,
            'character_id' => $seed->id,
            'character_name' => 'seedy',
            'character_realm' => 'the-maelstrom',
            'character_region' => 'eu',
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run->id,
            'character_id' => null,
            'character_name' => 'mateone',
            'character_realm' => 'twisting-nether',
            'character_region' => 'eu',
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run->id,
            'character_id' => null,
            'character_name' => 'matetwo',
            'character_realm' => 'silvermoon',
            'character_region' => 'eu',
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run->id,
            'character_id' => null,
            'character_name' => 'matethree',
            'character_realm' => 'kazzak',
            'character_region' => 'eu',
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run->id,
            'character_id' => null,
            'character_name' => 'matefour',
            'character_realm' => 'draenor',
            'character_region' => 'eu',
        ]);

        return $seed;
    }

    private function invokeCrawl(SyncCharacterData $job, Character $seed, int $season = 14): void
    {
        $reflection = new \ReflectionMethod($job, 'dispatchTeammateCrawl');
        $reflection->setAccessible(true);

        $gameDataClient = $this->createStub(BlizzardGameDataClient::class);
        $gameDataClient->method('getCurrentMythicPlusSeason')->willReturn($season);

        $reflection->invoke($job, $gameDataClient, $seed);
    }

    public function test_crawl_disabled_dispatches_only_seed(): void
    {
        Config::set('blizzard.sync.teammate_crawl_enabled', false);
        Bus::fake();

        SyncCharacterData::dispatch('eu', 'the-maelstrom', 'seedy', SyncDepth::Full);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 1);
    }

    public function test_seed_at_max_depth_does_not_fan_out(): void
    {
        Bus::fake();

        SyncCharacterData::dispatch('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 1);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 1);
    }

    public function test_recently_synced_teammate_is_skipped(): void
    {
        $seed = $this->makeSeedWithRun();

        $teammate = Character::factory()->create([
            'name' => 'mateone',
            'realm' => 'twisting-nether',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        DB::table('characters')
            ->where('id', $teammate->id)
            ->update(['updated_at' => now()->subSeconds(10)]);

        Bus::fake();

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 0);
        $this->invokeCrawl($job, $seed);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 3);
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn ($d) => $d->name === 'mateone',
        );
    }

    public function test_stale_teammate_is_dispatched_at_standard_and_depth_plus_one(): void
    {
        $seed = $this->makeSeedWithRun();

        $teammate = Character::factory()->create([
            'name' => 'mateone',
            'realm' => 'twisting-nether',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        DB::table('characters')
            ->where('id', $teammate->id)
            ->update(['updated_at' => now()->subSeconds(99999)]);

        Bus::fake();

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 0);
        $this->invokeCrawl($job, $seed);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 4);
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn ($d) => $d->crawlDepth === 1
                && $d->depth === SyncDepth::Standard
                && $d->queue === 'blizzard-background',
        );
    }

    public function test_seed_is_filtered_out_of_crawl_targets(): void
    {
        $seed = $this->makeSeedWithRun();

        Bus::fake();

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 0);
        $this->invokeCrawl($job, $seed);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 4);
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn ($d) => $d->name === 'seedy' && $d->crawlDepth === 1,
        );
    }

    public function test_duplicate_teammates_across_runs_dispatch_once(): void
    {
        $seed = $this->makeSeedWithRun();

        $run2 = DungeonRun::factory()->create([
            'season' => 14,
            'dungeon_id' => 5678,
            'completed_timestamp' => 1700000001000,
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run2->id,
            'character_id' => $seed->id,
            'character_name' => 'seedy',
            'character_realm' => 'the-maelstrom',
            'character_region' => 'eu',
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run2->id,
            'character_id' => null,
            'character_name' => 'mateone',
            'character_realm' => 'twisting-nether',
            'character_region' => 'eu',
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run2->id,
            'character_id' => null,
            'character_name' => 'newmate',
            'character_realm' => 'argent-dawn',
            'character_region' => 'eu',
        ]);

        Bus::fake();

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 0);
        $this->invokeCrawl($job, $seed);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 5);
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn ($d) => $d->name === 'newmate',
        );
    }

    public function test_max_depth_clamped_to_2(): void
    {
        Config::set('blizzard.crawl.max_depth', 999);

        $seed = $this->makeSeedWithRun();

        Bus::fake();

        $job = new SyncCharacterData('eu', 'the-maelstrom', 'seedy', SyncDepth::Full, null, 2);
        $this->invokeCrawl($job, $seed);

        Bus::assertNotDispatched(SyncCharacterData::class);
    }
}
