<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeHeavySlices extends Command
{
    protected $signature = 'blizzard:purge-heavy-slices
                            {--achievements : Truncate character_achievements and null achievements_synced_at}
                            {--pets : Truncate character_pets and null collections_synced_at (also re-triggers mounts/toys re-fetch since the slice timestamp is shared)}
                            {--all : Purge both achievements and pets}';

    protected $description = 'Delete persisted data for disk-heavy slices. --pets nulls collections_synced_at, which forces a full re-fetch of the collections slice (mounts + toys included), since that timestamp is shared across the whole slice.';

    public function handle(): int
    {
        $doAchievements = $this->option('achievements') || $this->option('all');
        $doPets = $this->option('pets') || $this->option('all');

        if (! $doAchievements && ! $doPets) {
            $this->error('No slice specified. Pass --achievements, --pets, or --all.');

            return self::FAILURE;
        }

        if ($doAchievements) {
            $deleted = DB::table('character_achievements')->count();
            DB::table('character_achievements')->truncate();
            $nulled = Character::query()->whereNotNull('achievements_synced_at')->count();
            Character::query()->update(['achievements_synced_at' => null]);
            $this->info("achievements: {$deleted} rows deleted, {$nulled} characters reset.");
        }

        if ($doPets) {
            $deleted = DB::table('character_pets')->count();
            DB::table('character_pets')->truncate();
            $nulled = Character::query()->whereNotNull('collections_synced_at')->count();
            Character::query()->update(['collections_synced_at' => null]);
            $this->info("pets: {$deleted} rows deleted, {$nulled} characters' collections_synced_at reset (mounts/toys re-fetch will also be triggered on next sync).");
        }

        return self::SUCCESS;
    }
}
