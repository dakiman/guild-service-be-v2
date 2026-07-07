<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneSubmaxSlices extends Command
{
    protected $signature = 'characters:prune-submax-slices {--dry-run : Count without deleting} {--chunk=500 : Characters per batch}';

    protected $description = 'One-off: delete slice rows + null slice timestamps for sub-endgame characters synced before endgame-only gating';

    /** Slice tables keyed by character_id. dungeon_runs stay — shared entities. */
    private const SLICE_TABLES = [
        'character_pvp_brackets',
        'character_professions',
        'raid_encounter_kills',
        'character_mounts',
        'character_pets',
        'character_toys',
        'character_achievements',
    ];

    private const SLICE_TIMESTAMPS = [
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
        'stats_synced_at',
        'titles_synced_at',
        'reputations_synced_at',
        'collections_synced_at',
        'achievements_synced_at',
    ];

    public function handle(): int
    {
        $endgame = (int) config('blizzard.endgame_level', 90);
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $targets = Character::query()
            ->where('game_version', 'retail')
            ->where('level', '<', $endgame)
            ->where(function ($q) {
                foreach (self::SLICE_TIMESTAMPS as $field) {
                    $q->orWhereNotNull($field);
                }
                $q->orWhereNotNull('stats');
            });

        $total = $targets->count();

        if ($dryRun) {
            $rowCounts = [];
            $ids = $targets->pluck('id');
            foreach (self::SLICE_TABLES as $table) {
                $count = DB::table($table)->whereIn('character_id', $ids)->count();
                if ($count > 0) {
                    $rowCounts[] = "{$table}: {$count}";
                }
            }
            $this->info("[dry-run] {$total} sub-endgame characters carry stale slice data.");
            foreach ($rowCounts as $line) {
                $this->line("[dry-run] {$line}");
            }

            return self::SUCCESS;
        }

        $pruned = 0;
        $targets->select('id')->chunkById($chunk, function ($characters) use (&$pruned) {
            $ids = $characters->pluck('id')->all();

            foreach (self::SLICE_TABLES as $table) {
                DB::table($table)->whereIn('character_id', $ids)->delete();
            }

            // DB::table update: never bumps updated_at, the profile-sync clock.
            DB::table('characters')->whereIn('id', $ids)->update(
                array_fill_keys(self::SLICE_TIMESTAMPS, null)
                    + ['stats' => null, 'title_ids' => null, 'reputations' => null],
            );

            $pruned += count($ids);
        });

        $this->info("Pruned slice data for {$pruned} sub-endgame characters (of {$total} matched).");

        return self::SUCCESS;
    }
}
