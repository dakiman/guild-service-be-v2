<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeHeavySlices extends Command
{
    protected $signature = 'blizzard:purge-heavy-slices
                            {--mounts : Truncate character_mounts and null collections_synced_at}
                            {--toys : Truncate character_toys and null collections_synced_at}
                            {--achievements : Truncate character_achievements and null achievements_synced_at}
                            {--pets : Truncate character_pets and null collections_synced_at}
                            {--all : Purge all heavy slices (mounts, toys, achievements, pets)}';

    protected $description = 'Delete persisted data for disk-heavy slices. --mounts, --toys, and --pets null collections_synced_at, which forces a full re-fetch of the collections slice on next sync.';

    public function handle(): int
    {
        $doMounts = $this->option('mounts') || $this->option('all');
        $doToys = $this->option('toys') || $this->option('all');
        $doAchievements = $this->option('achievements') || $this->option('all');
        $doPets = $this->option('pets') || $this->option('all');

        if (! $doMounts && ! $doToys && ! $doAchievements && ! $doPets) {
            $this->error('No slice specified. Pass --mounts, --toys, --achievements, --pets, or --all.');

            return self::FAILURE;
        }

        $resetCollectionsSyncedAt = false;

        if ($doMounts) {
            $deleted = DB::table('character_mounts')->count();
            DB::table('character_mounts')->truncate();
            $this->info("mounts: {$deleted} rows deleted.");
            $resetCollectionsSyncedAt = true;
        }

        if ($doToys) {
            $deleted = DB::table('character_toys')->count();
            DB::table('character_toys')->truncate();
            $this->info("toys: {$deleted} rows deleted.");
            $resetCollectionsSyncedAt = true;
        }

        if ($doPets) {
            $deleted = DB::table('character_pets')->count();
            DB::table('character_pets')->truncate();
            $this->info("pets: {$deleted} rows deleted.");
            $resetCollectionsSyncedAt = true;
        }

        if ($resetCollectionsSyncedAt) {
            // DB::table, not Eloquent: a maintenance reset must not bump the whole
            // table's updated_at (the profile-sync clock isStale() reads). (P1.1)
            $nulled = DB::table('characters')->whereNotNull('collections_synced_at')->count();
            DB::table('characters')->update(['collections_synced_at' => null]);
            $this->info("collections_synced_at reset on {$nulled} characters.");
        }

        if ($doAchievements) {
            $deleted = DB::table('character_achievements')->count();
            DB::table('character_achievements')->truncate();
            $nulled = DB::table('characters')->whereNotNull('achievements_synced_at')->count();
            DB::table('characters')->update(['achievements_synced_at' => null]);
            $this->info("achievements: {$deleted} rows deleted, {$nulled} characters reset.");
        }

        return self::SUCCESS;
    }
}
