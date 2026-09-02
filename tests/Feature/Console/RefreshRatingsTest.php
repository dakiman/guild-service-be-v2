<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Models\GameDataSeason;
use App\Models\LadderRun;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RefreshRatingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['blizzard.rating_refresh.enabled' => true, 'blizzard.rating_refresh.daily_cap' => 40000]);
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'MN Season 2', 'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11, 'is_current' => true, 'started_at' => '2026-08-22 00:00:00',
        ]);
        Seasons::clearCache();
        Bus::fake([SyncCharacterData::class]);
    }

    /** @param array<string, mixed> $overrides */
    private function stale(string $name, int $rating, array $overrides = []): Character
    {
        return Character::factory()->create(array_merge([
            'name' => $name, 'region' => 'eu', 'realm' => 'draenor', 'level' => 90,
            'mythic_plus_rating' => $rating, 'rating_synced_at' => '2026-08-01 00:00:00',
        ], $overrides));
    }

    public function test_backlog_is_untagged_or_not_synced_this_season(): void
    {
        $this->stale('untagged', 2000, ['rating_season_id' => null, 'rating_synced_at' => '2026-08-25 00:00:00']);
        $this->stale('never', 2000, ['rating_season_id' => null, 'rating_synced_at' => null]);
        $this->stale('lastseason_stale', 2000, ['rating_season_id' => 17, 'rating_synced_at' => '2026-08-01 00:00:00']);
        $this->stale('lastseason_checked', 2000, ['rating_season_id' => 17, 'rating_synced_at' => '2026-08-25 00:00:00']);
        $this->stale('current', 2000, ['rating_season_id' => 18, 'rating_synced_at' => '2026-08-25 00:00:00']);
        $this->stale('unrated', 0);
        $this->stale('lowbie', 2000, ['level' => 80]);

        $this->artisan('ratings:refresh')->assertExitCode(0);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 3);
        foreach (['untagged', 'never', 'lastseason_stale'] as $name) {
            Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === $name
                && $j->depth === SyncDepth::Shallow && $j->origin === SyncOrigin::Proactive);
        }
        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'lastseason_checked');
        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'current');
    }

    public function test_ladder_members_come_first_then_highest_rating_and_cap_applies(): void
    {
        $this->stale('richest', 3000);
        $this->stale('ladderguy', 1500);
        $this->stale('middle', 2000);
        $run = LadderRun::create([
            'period_id' => 1078, 'region' => 'eu', 'dungeon_id' => 504, 'keystone_level' => 12, 'duration' => 1_500_000,
            'completed_timestamp' => 1756700000000, 'is_completed_on_time' => true, 'run_hash' => sha1('x'),
        ]);
        $run->memberEntries()->create(['name' => 'Ladderguy', 'realm_slug' => 'draenor', 'spec_id' => 62]);

        $this->artisan('ratings:refresh', ['--cap' => 2])->assertExitCode(0);

        Bus::assertDispatchedTimes(SyncCharacterData::class, 2);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'ladderguy');
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'richest');
        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'middle');
    }

    public function test_disabled_flag_dispatches_nothing(): void
    {
        config(['blizzard.rating_refresh.enabled' => false]);
        $this->stale('stale', 2000);

        $this->artisan('ratings:refresh')->expectsOutputToContain('disabled')->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_open_circuit_dispatches_nothing(): void
    {
        Cache::put('blizzard:unhealthy', true, 60);
        $this->stale('stale', 2000);

        $this->artisan('ratings:refresh')->expectsOutputToContain('circuit')->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_reports_remaining_backlog(): void
    {
        $this->stale('a', 2000);
        $this->stale('b', 2000);
        $this->stale('c', 2000);

        $this->artisan('ratings:refresh', ['--cap' => 2])
            ->expectsOutputToContain('Dispatched 2')
            ->expectsOutputToContain('1 remaining')
            ->assertExitCode(0);
    }
}
