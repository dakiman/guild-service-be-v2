<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GameDataSeeder extends Seeder
{
    private const SNAPSHOT_PATH = 'database/snapshots/game-data.json';

    /**
     * Tables restored from snapshot. Order matters: parents before children
     * (raid_instances before raid_encounters). game_data_expansions is
     * intentionally excluded — owned by GameDataExpansionSeeder (hardcoded
     * source of truth, also the FK target for several rows here).
     */
    private const TABLES = [
        'game_data_factions',
        'game_data_titles',
        'game_data_mounts',
        'game_data_achievement_categories',
        'game_data_achievements',
        'game_data_raid_instances',
        'game_data_raid_encounters',
        'game_data_mythic_keystone_dungeons',
        'game_data_keystone_affixes',
        'game_data_talent_trees',
    ];

    public function run(): void
    {
        $this->call(GameDataExpansionSeeder::class);
        $this->call(GameDataSeasonSeeder::class);

        $path = base_path(self::SNAPSHOT_PATH);

        if (is_file($path)) {
            $this->command->info("Loading game-data snapshot from {$path}...");
            $this->loadSnapshot($path);

            return;
        }

        $this->command->warn('No game-data snapshot at '.self::SNAPSHOT_PATH.' — falling back to Blizzard API sync.');
        Artisan::call('blizzard:sync-game-data', [], $this->command->getOutput());
    }

    private function loadSnapshot(string $path): void
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read snapshot at {$path}");
        }

        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        Schema::withoutForeignKeyConstraints(function () use ($data) {
            DB::transaction(function () use ($data) {
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->delete();
                }

                foreach (self::TABLES as $table) {
                    $rows = $data[$table] ?? [];
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                    $this->command->info("  {$table}: ".count($rows).' rows');
                }
            });
        });
    }
}
