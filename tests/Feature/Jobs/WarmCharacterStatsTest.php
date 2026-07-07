<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\WarmCharacterStats;
use App\Models\Character;
use App\Models\RaidEncounterKill;
use App\Services\CharacterStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmCharacterStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('blizzard.mythic_plus.season_override', 14);
    }

    public function test_dispatching_the_job_populates_the_stats_cache(): void
    {
        $character = Character::factory()->create(['level' => 90, 'class_id' => 1]);
        RaidEncounterKill::factory()->create(['character_id' => $character->id]);

        $this->assertNull(Cache::get(CharacterStatsService::CACHE_KEY));

        WarmCharacterStats::dispatch();

        $cached = Cache::get(CharacterStatsService::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertSame(1, $cached['total_characters']);
    }

    public function test_warm_overwrites_an_existing_cached_value_without_a_gap(): void
    {
        Cache::forever(CharacterStatsService::CACHE_KEY, ['total_characters' => 999]);

        $character = Character::factory()->create(['level' => 90, 'class_id' => 1]);
        RaidEncounterKill::factory()->create(['character_id' => $character->id]);

        app(CharacterStatsService::class)->warm();

        $this->assertSame(1, Cache::get(CharacterStatsService::CACHE_KEY)['total_characters']);
    }

    public function test_get_stats_serves_the_cached_value_even_if_stale_without_recomputing(): void
    {
        // A sentinel that computeStats() could never produce — if getStats()
        // recomputes, the assertion fails.
        Cache::forever(CharacterStatsService::CACHE_KEY, ['total_characters' => 424242]);

        $stats = app(CharacterStatsService::class)->getStats();

        $this->assertSame(424242, $stats['total_characters']);
    }

    public function test_get_stats_on_empty_cache_dispatches_one_warm_job_and_returns_empty_shape(): void
    {
        Queue::fake();

        $character = Character::factory()->create(['level' => 90, 'class_id' => 1]);
        RaidEncounterKill::factory()->create(['character_id' => $character->id]);

        $service = app(CharacterStatsService::class);

        // Simulate multiple users landing on the page at once.
        $first = $service->getStats();
        $second = $service->getStats();

        // Nobody computed in-request: the payload is the empty shape and the
        // cache is still unpopulated (only the job writes it).
        $this->assertSame(0, $first['total_characters']);
        $this->assertSame($first, $second);
        $this->assertNull(Cache::get(CharacterStatsService::CACHE_KEY));

        // ShouldBeUnique collapses the concurrent dispatches into one job.
        Queue::assertPushed(WarmCharacterStats::class, 1);
    }
}
