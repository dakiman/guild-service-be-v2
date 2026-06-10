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
        Cache::put(CharacterStatsService::CACHE_KEY, ['total_characters' => 999], CharacterStatsService::CACHE_TTL);

        $character = Character::factory()->create(['level' => 90, 'class_id' => 1]);
        RaidEncounterKill::factory()->create(['character_id' => $character->id]);

        app(CharacterStatsService::class)->warm();

        $this->assertSame(1, Cache::get(CharacterStatsService::CACHE_KEY)['total_characters']);
    }
}
