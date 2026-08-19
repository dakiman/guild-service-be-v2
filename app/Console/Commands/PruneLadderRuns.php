<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LadderRun;
use App\Models\LadderRunMember;
use Illuminate\Console\Command;

class PruneLadderRuns extends Command
{
    protected $signature = 'ladder:prune
        {--keep=8 : Newest distinct periods to retain}
        {--batch=10000 : Runs per delete batch}
        {--dry-run : Count without deleting}';

    protected $description = 'Delete raw ladder runs/members beyond the newest N periods (meta_snapshots keep the history)';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $batch = max(1, (int) $this->option('batch'));

        $prunable = LadderRun::query()
            ->select('period_id')
            ->distinct()
            ->orderByDesc('period_id')
            ->pluck('period_id')
            ->slice($keep)
            ->values();

        if ($prunable->isEmpty()) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($prunable as $periodId) {
            if ($this->option('dry-run')) {
                $count = LadderRun::where('period_id', $periodId)->count();
                $this->line("[dry-run] period {$periodId}: {$count} runs");
                $total += $count;

                continue;
            }

            $periodTotal = 0;
            do {
                $runIds = LadderRun::where('period_id', $periodId)->limit($batch)->pluck('id');
                if ($runIds->isEmpty()) {
                    break;
                }
                LadderRunMember::whereIn('ladder_run_id', $runIds)->delete();
                LadderRun::whereKey($runIds)->delete();
                $periodTotal += $runIds->count();
            } while (true);

            $this->line("period {$periodId}: deleted {$periodTotal} runs");
            $total += $periodTotal;
        }

        $verb = $this->option('dry-run') ? 'would delete' : 'deleted';
        $this->info("ladder:prune {$verb} {$total} runs across {$prunable->count()} periods.");

        return self::SUCCESS;
    }
}
