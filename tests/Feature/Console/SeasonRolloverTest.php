<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\DungeonRun;
use App\Models\GameDataSeason;
use App\Models\SeasonArchive;
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

        $this->rollover()
            ->expectsConfirmation('Proceed with the rollover?', 'yes')
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
