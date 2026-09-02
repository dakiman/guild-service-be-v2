<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\Character;
use App\Models\CharacterRank;
use App\Models\DungeonRun;
use App\Models\GameDataSeason;
use App\Models\SeasonArchive;
use App\Services\RaiderIO\RaiderIOClient;
use App\Support\Seasons;
use Database\Seeders\GameDataExpansionSeeder;
use Database\Seeders\GameDataSeasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SeasonRolloverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // queue=sync in tests would run WarmCharacterStats (the ~heavy stats
        // compute) inline during the command — fake it; the dispatch itself
        // is the contract.
        Queue::fake();
        $this->seed(GameDataExpansionSeeder::class);
        $this->seed(GameDataSeasonSeeder::class);

        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMythicPlusSeasonIndex')->willReturn([
            'seasons' => [['id' => 15], ['id' => 17], ['id' => 18]],
            'current_season' => ['id' => 18],
        ]);
        $mock->method('getMythicKeystoneSeason')->willReturn([
            'id' => 18,
            'start_timestamp' => 1797000000000,
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->mockRaiderIO(['tier-mn-2', 'the-venomous-abyss']);
    }

    /** @param list<string>|\Throwable $activeRaids */
    private function mockRaiderIO(array|\Throwable $activeRaids): void
    {
        $rio = $this->createMock(RaiderIOClient::class);
        $activeRaids instanceof \Throwable
            ? $rio->method('activeRaidSlugs')->willThrowException($activeRaids)
            : $rio->method('activeRaidSlugs')->willReturn($activeRaids);
        $this->app->instance(RaiderIOClient::class, $rio);
    }

    private function rollover(array $extra = []): PendingCommand
    {
        return $this->artisan('season:rollover', array_merge([
            '--blizzard-id' => 18,
            '--slug' => 'season-mn-2',
            '--name' => 'Midnight Season 2',
            '--tier-slug' => 'tier-mn-2',
            '--skip-sync' => true,
        ], $extra));
    }

    public function test_happy_path_snapshots_and_flips(): void
    {
        DungeonRun::factory()->count(3)->create(['season' => 17, 'is_completed_on_time' => true]);
        // Rated under the outgoing season → its standings must freeze as season 17.
        Character::factory()->create(['name' => 'veteran', 'region' => 'eu', 'realm' => 'draenor', 'level' => 90, 'mythic_plus_rating' => 2500, 'rating_season_id' => 17]);

        $this->rollover()
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
            ->expectsOutputToContain('season-mn-1 standings are frozen')
            ->assertSuccessful();

        // Archive frozen for the outgoing season.
        $archive = SeasonArchive::find(17);
        $this->assertNotNull($archive);
        $this->assertSame(3, $archive->payload['meta']['total_runs']);

        // Registry flipped.
        $old = GameDataSeason::find(17);
        $new = GameDataSeason::find(18);
        $this->assertFalse((bool) $old->is_current);
        $this->assertNotNull($old->ended_at);
        $this->assertTrue((bool) $new->is_current);
        $this->assertSame('season-mn-2', $new->slug);
        $this->assertSame('tier-mn-2', $new->raiderio_tier_slug);
        // Defaults inherited from the outgoing season.
        $this->assertSame(11, $new->raiderio_expansion_id);
        $this->assertSame(12, $new->expansion_id);
        $this->assertNotNull($new->started_at);

        // Registry cache cleared: resolver sees the new id immediately.
        $this->assertSame(18, Seasons::currentId());

        // Ranks were materialized BEFORE the flip: the row carries the old season id
        // and the new season starts empty.
        $this->assertSame(1, CharacterRank::query()->where('season_id', 17)->count());
        $this->assertSame(0, CharacterRank::query()->where('season_id', 18)->count());
    }

    public function test_rejects_tier_slug_raiderio_does_not_serve(): void
    {
        // 2026-08-22: the operator guessed `tier-mn-2`; raider.io had shipped
        // S2 as two individual raids and /raiding/raid-rankings 400'd for a
        // week, silently emptying the guild seed.
        $this->mockRaiderIO(['the-venomous-abyss', 'the-tidebound-grotto']);

        $this->rollover(['--tier-slug' => 'tier-mn-2'])
            ->expectsOutputToContain('the-venomous-abyss')
            ->assertFailed();

        $this->assertTrue((bool) GameDataSeason::find(17)->is_current);
        $this->assertSame(0, SeasonArchive::count());
    }

    public function test_raiderio_outage_does_not_block_the_rollover(): void
    {
        $this->mockRaiderIO(new \RuntimeException('raider.io down'));

        $this->rollover()
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
            ->assertSuccessful();

        $this->assertTrue((bool) GameDataSeason::find(18)->is_current);
    }

    public function test_rejects_id_missing_from_blizzard_index(): void
    {
        $this->rollover(['--blizzard-id' => 99])
            ->assertFailed();

        $this->assertTrue((bool) GameDataSeason::find(17)->is_current);
    }

    public function test_rerun_refuses_to_overwrite_archive_without_force(): void
    {
        $this->rollover()
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
            ->assertSuccessful();

        // Flip back so the command sees 17 as current again (simulates a
        // re-run after a partial failure post-snapshot).
        GameDataSeason::where('id', 18)->update(['is_current' => false]);
        GameDataSeason::where('id', 17)->update(['is_current' => true, 'ended_at' => null]);
        Seasons::clearCache();

        $this->rollover()
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
            ->assertFailed();

        // --force allows the overwrite and completes the flip.
        $this->rollover(['--force' => true])
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
            ->assertSuccessful();

        $this->assertTrue((bool) GameDataSeason::find(18)->is_current);
    }

    public function test_expansion_boundary_prints_manual_checklist(): void
    {
        $this->rollover(['--expansion-id' => 13])
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
            ->expectsOutputToContain('GameDataExpansionSeeder')
            ->assertSuccessful();
    }
}
