<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillRatingSeasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'MN Season 2', 'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11, 'is_current' => true, 'started_at' => '2026-08-22 00:00:00',
        ]);
        Seasons::clearCache();
    }

    /** @param array<string, mixed> $overrides */
    private function rated(string $name, array $overrides = []): Character
    {
        return Character::factory()->create(array_merge([
            'name' => $name, 'region' => 'eu', 'realm' => 'draenor', 'level' => 90,
            'mythic_plus_rating' => 2500, 'rating_season_id' => null,
            'mythics_synced_at' => '2026-08-25 12:00:00',
        ], $overrides));
    }

    private function runFor(Character $c, int $season): void
    {
        $run = DungeonRun::factory()->create(['season' => $season, 'is_completed_on_time' => true]);
        DB::table('dungeon_run_members')->insert([
            'dungeon_run_id' => $run->id, 'character_id' => $c->id,
            'character_name' => $c->name, 'character_realm' => $c->realm, 'character_region' => $c->region,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_tags_only_provable_current_season_ratings(): void
    {
        $provable = $this->rated('provable');
        $this->runFor($provable, 18);
        $oldRunsOnly = $this->rated('oldruns');
        $this->runFor($oldRunsOnly, 17);
        $staleSlice = $this->rated('staleslice', ['mythics_synced_at' => '2026-08-01 00:00:00']);
        $this->runFor($staleSlice, 18);
        $already = $this->rated('already', ['rating_season_id' => 17]);
        $this->runFor($already, 18);
        $unrated = $this->rated('unrated', ['mythic_plus_rating' => 0]);
        $this->runFor($unrated, 18);
        $this->rated('noruns');

        $this->artisan('ratings:backfill-season', ['--chunk' => 2])
            ->expectsOutputToContain('Tagged 1 characters with season 18')
            ->assertExitCode(0);

        $this->assertSame(18, $provable->fresh()->rating_season_id);
        $this->assertNull($oldRunsOnly->fresh()->rating_season_id);
        $this->assertNull($staleSlice->fresh()->rating_season_id);
        $this->assertSame(17, $already->fresh()->rating_season_id);
        $this->assertNull($unrated->fresh()->rating_season_id);
        $this->assertNull(Character::where('name', 'noruns')->first()->rating_season_id);
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $c = $this->rated('provable');
        $this->runFor($c, 18);

        $this->artisan('ratings:backfill-season', ['--dry-run' => true])
            ->expectsOutputToContain('1 characters would be tagged with season 18')
            ->assertExitCode(0);

        $this->assertNull($c->fresh()->rating_season_id);
    }

    public function test_explicit_season_and_missing_season_are_handled(): void
    {
        GameDataSeason::create([
            'id' => 17, 'slug' => 'season-mn-1', 'name' => 'MN Season 1', 'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11, 'is_current' => false, 'started_at' => '2026-03-18 00:00:00',
        ]);
        $c = $this->rated('s17', ['mythics_synced_at' => '2026-04-01 00:00:00']);
        $this->runFor($c, 17);

        $this->artisan('ratings:backfill-season', ['--season' => 17])->assertExitCode(0);
        $this->assertSame(17, $c->fresh()->rating_season_id);

        $this->artisan('ratings:backfill-season', ['--season' => 99])->assertExitCode(1);
    }
}
