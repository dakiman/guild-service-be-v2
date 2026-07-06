<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\WarmRaidKillStats;
use App\Models\Character;
use App\Models\RaidEncounterKill;
use App\Services\RaidKillStatsService;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarmRaidKillStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    public function test_dispatching_the_job_makes_heatmap_requests_serve_without_touching_kill_tables(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);
        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        WarmRaidKillStats::dispatch();

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach (RaidKillStatsService::WARM_DIFFICULTIES as $difficulty) {
            $this->getJson("/api/v1/stats/characters/raid-kills?difficulty={$difficulty}")->assertOk();
        }
        $killQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'raid_encounter_kills'),
        );
        DB::disableQueryLog();

        $this->assertCount(0, $killQueries, 'Warmed difficulties must be served entirely from cache.');
    }

    public function test_warm_overwrites_an_existing_cached_value_without_a_gap(): void
    {
        Cache::put('stats:raid-kills:heroic:Midnight', ['raids' => [['instance_id' => 999]]], 600);

        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);
        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'instance_id' => 1234,
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        app(RaidKillStatsService::class)->warm();

        $cached = Cache::get('stats:raid-kills:heroic:Midnight');
        $this->assertSame(1234, $cached['raids'][0]['instance_id']);
    }
}
