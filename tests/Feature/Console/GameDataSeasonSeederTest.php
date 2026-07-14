<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\GameDataSeason;
use Database\Seeders\GameDataExpansionSeeder;
use Database\Seeders\GameDataSeasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataSeasonSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_mn1_as_current_season(): void
    {
        $this->seed(GameDataExpansionSeeder::class);
        $this->seed(GameDataSeasonSeeder::class);

        $season = GameDataSeason::where('is_current', true)->first();

        $this->assertNotNull($season);
        $this->assertSame(17, $season->id);
        $this->assertSame('season-mn-1', $season->slug);
        $this->assertSame('Midnight Season 1', $season->name);
        $this->assertSame('tier-mn-1', $season->raiderio_tier_slug);
        $this->assertSame(11, $season->raiderio_expansion_id);
        $this->assertSame(12, $season->expansion_id);
    }

    public function test_reseeding_after_rollover_does_not_flip_current_back(): void
    {
        $this->seed(GameDataExpansionSeeder::class);
        $this->seed(GameDataSeasonSeeder::class);

        // Simulate a completed rollover to a future season.
        GameDataSeason::where('id', 17)->update(['is_current' => false]);
        GameDataSeason::create([
            'id' => 18,
            'slug' => 'season-mn-2',
            'name' => 'Midnight Season 2',
            'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11,
            'expansion_id' => 12,
            'is_current' => true,
        ]);

        // Additive-only seeder: must NOT resurrect MN-1 as current.
        $this->seed(GameDataSeasonSeeder::class);

        $this->assertFalse((bool) GameDataSeason::find(17)->is_current);
        $this->assertTrue((bool) GameDataSeason::find(18)->is_current);
    }
}
