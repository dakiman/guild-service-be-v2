<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GameDataSeason;
use Illuminate\Database\Seeder;

class GameDataSeasonSeeder extends Seeder
{
    /**
     * Bootstrap rows only. New seasons are inserted by `season:rollover`,
     * NOT by editing this file — which is why this seeder is additive-only
     * (firstOrCreate): re-seeding after a rollover must never flip
     * is_current back to a historical season.
     *
     * id = Blizzard's mythic-keystone season id (same value as
     * dungeon_runs.season). slug doubles as the raider.io season slug.
     */
    private const SEASONS = [
        [
            'id' => 17,
            'slug' => 'season-mn-1',
            'name' => 'Midnight Season 1',
            'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11,
            'expansion_id' => 12, // Midnight in game_data_expansions
            'is_current' => true,
            'started_at' => '2026-03-18 00:00:00',
        ],
    ];

    public function run(): void
    {
        foreach (self::SEASONS as $row) {
            GameDataSeason::firstOrCreate(['id' => $row['id']], $row);
        }
    }
}
