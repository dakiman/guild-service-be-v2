<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Support\KeystoneTimers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecomputeLadderTimed extends Command
{
    protected $signature = 'ladder:recompute-timed {--dry-run : Count without updating}';

    protected $description = 'Backfill is_completed_on_time for ladder runs ingested while a dungeon timer was unknown';

    public function handle(): int
    {
        $healed = 0;

        foreach (GameDataMythicKeystoneDungeon::query()->whereNotNull('keystone_upgrades')->get() as $dungeon) {
            $timerMs = KeystoneTimers::plusOne($dungeon->keystone_upgrades);
            if ($timerMs === null) {
                continue;
            }

            $query = DB::table('ladder_runs')
                ->where('dungeon_id', $dungeon->id)
                ->whereNull('is_completed_on_time');

            if ($this->option('dry-run')) {
                $count = $query->count();
            } else {
                $count = $query->update(['is_completed_on_time' => DB::raw('duration <= '.(int) $timerMs)]);
            }

            if ($count > 0) {
                $this->line("{$dungeon->name} ({$dungeon->id}): {$count} rows");
                $healed += $count;
            }
        }

        $verb = $this->option('dry-run') ? 'would heal' : 'healed';
        $this->info("ladder:recompute-timed {$verb} {$healed} rows.");
        $remaining = DB::table('ladder_runs')->whereNull('is_completed_on_time')->count();
        if ($remaining > 0 && ! $this->option('dry-run')) {
            $this->warn("{$remaining} rows still unknown (dungeons without timers). Re-run after blizzard:sync-game-data pve.");
        }
        if ($healed > 0 && ! $this->option('dry-run')) {
            $this->warn('Re-run meta:warm --period=<id> for affected periods so snapshots pick the healed rates up.');
        }

        return self::SUCCESS;
    }
}
