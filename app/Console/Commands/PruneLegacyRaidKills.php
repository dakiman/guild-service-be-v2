<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RaidRetention;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PruneLegacyRaidKills extends Command
{
    protected $signature = 'raids:prune-legacy {--dry-run : Count without deleting} {--batch=50000 : Rows per delete batch}';

    protected $description = 'Delete legacy-expansion raid_encounter_kills rows for never-searched characters';

    public function handle(): int
    {
        $retained = RaidRetention::expansions();

        if ($retained === null) {
            $this->error('game_data_expansions is empty — cannot resolve the current expansion; refusing to prune.');

            return self::FAILURE;
        }

        $legacy = array_values(array_diff($this->allExpansionNames(), $retained));

        if ($legacy === []) {
            $this->info('No legacy expansions present. Nothing to prune.');

            return self::SUCCESS;
        }

        $batch = max(1, (int) $this->option('batch'));
        $total = 0;

        foreach ($legacy as $expansion) {
            if ($this->option('dry-run')) {
                $count = $this->prunable($expansion)->count();
                $total += $count;
                $this->line("[dry-run] {$expansion}: {$count} rows");

                continue;
            }

            $expansionTotal = 0;
            do {
                // Batched delete keyed off the (expansion_name, difficulty)
                // index; LIMIT-in-subquery works on Postgres and SQLite.
                $deleted = DB::table('raid_encounter_kills')
                    ->whereIn('id', function (Builder $q) use ($expansion, $batch) {
                        $q->select('id')
                            ->from('raid_encounter_kills')
                            ->where('expansion_name', $expansion)
                            ->whereIn('character_id', function (Builder $c) {
                                $c->select('id')->from('characters')->where('num_of_searches', 0);
                            })
                            ->limit($batch);
                    })
                    ->delete();
                $expansionTotal += $deleted;
            } while ($deleted > 0);

            $total += $expansionTotal;
            $this->line("{$expansion}: deleted {$expansionTotal} rows");
        }

        $verb = $this->option('dry-run') ? 'would delete' : 'deleted';
        $this->info("Total: {$verb} {$total} rows across ".count($legacy).' legacy expansions.');

        return self::SUCCESS;
    }

    private function prunable(string $expansion): Builder
    {
        return DB::table('raid_encounter_kills')
            ->where('expansion_name', $expansion)
            ->whereIn('character_id', function (Builder $c) {
                $c->select('id')->from('characters')->where('num_of_searches', 0);
            });
    }

    /**
     * Loose index scan (chained MIN() probes on the expansion_name btree).
     * SELECT DISTINCT over ~96M rows takes ~21s on prod; this takes ~2ms.
     */
    private function allExpansionNames(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE exps(name) AS (
                SELECT MIN(expansion_name) FROM raid_encounter_kills
                UNION ALL
                SELECT (SELECT MIN(expansion_name) FROM raid_encounter_kills WHERE expansion_name > exps.name)
                FROM exps WHERE exps.name IS NOT NULL
            )
            SELECT name FROM exps WHERE name IS NOT NULL ORDER BY name
        SQL);

        return array_map(fn (object $row) => $row->name, $rows);
    }
}
