<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairDungeonRunMemberCharacterIds extends Command
{
    protected $signature = 'blizzard:repair-dungeon-run-member-character-ids {--dry-run}';

    protected $description = "Null out dungeon_run_members.character_id where the linked character's identity disagrees with the row's named identity (cleans up pre-fix data from the team-pivot bug).";

    public function handle(): int
    {
        $staleQuery = function () {
            return DB::table('dungeon_run_members as drm')
                ->join('characters as c', 'c.id', '=', 'drm.character_id')
                ->whereNotNull('drm.character_id')
                ->where(function ($q) {
                    $q->whereRaw('c.name != LOWER(drm.character_name)')
                        ->orWhereColumn('c.realm', '!=', 'drm.character_realm')
                        ->orWhereColumn('c.region', '!=', 'drm.character_region');
                });
        };

        $count = $staleQuery()->count();
        $this->info("Found {$count} stale rows.");

        if ($count > 0) {
            $sample = $staleQuery()
                ->select(
                    'drm.id',
                    'drm.character_name',
                    'drm.character_realm',
                    'drm.character_region',
                    'c.name as linked_name',
                    'c.realm as linked_realm',
                    'c.region as linked_region',
                )
                ->limit(3)
                ->get();
            $this->line('Sample: '.$sample->toJson());
        }

        if ($this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }

        $total = 0;
        do {
            $ids = $staleQuery()->limit(1000)->pluck('drm.id');
            if ($ids->isEmpty()) {
                break;
            }
            DB::table('dungeon_run_members')
                ->whereIn('id', $ids->all())
                ->update(['character_id' => null]);
            $total += $ids->count();
        } while ($ids->count() === 1000);

        $this->info("Repaired {$total} rows.");

        return self::SUCCESS;
    }
}
