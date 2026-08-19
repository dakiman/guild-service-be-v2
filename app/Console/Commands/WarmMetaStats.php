<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LadderRun;
use App\Services\MetaStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WarmMetaStats extends Command
{
    protected $signature = 'meta:warm {--period= : Blizzard period id (default: the two most recent periods with ladder data)}';

    protected $description = 'Aggregate ladder runs into meta_snapshots (specs, dungeons, comps per region scope)';

    public function handle(MetaStatsService $service): int
    {
        $coverage = null;

        if ($this->option('period') === null) {
            $today = now()->format('Y-m-d');

            if (Cache::get("meta:warmed:{$today}") !== null) {
                return self::SUCCESS; // already warmed today
            }

            $dispatched = (int) Cache::get("ladder-crawl:{$today}:dispatched", 0);
            $fetched = (int) Cache::get("ladder-crawl:{$today}:fetched", 0);

            if ($dispatched > 0 && $fetched < $dispatched && now()->lt(now()->setTime(11, 0))) {
                $this->info("Crawl still draining ({$fetched}/{$dispatched} shards) — deferring warm.");

                return self::SUCCESS;
            }

            if ($dispatched > 0 && $fetched < $dispatched) {
                $coverage = ['fetched' => $fetched, 'dispatched' => $dispatched];
                $this->warn("Deadline warm with partial crawl ({$fetched}/{$dispatched} shards) — snapshots stamped.");
            }

            Cache::put("meta:warmed:{$today}", true, now()->addHours(36));
        }

        $this->logCrawlSummary();

        $periods = $this->option('period') !== null
            ? [(int) $this->option('period')]
            : LadderRun::query()
                ->select('period_id')
                ->distinct()
                ->orderByDesc('period_id')
                ->limit(2)
                ->pluck('period_id')
                ->all();

        if ($periods === []) {
            $this->warn('No ladder data to aggregate yet.');

            return self::SUCCESS;
        }

        foreach ($periods as $periodId) {
            $start = microtime(true);
            $service->warm($periodId, $coverage);
            $this->info("Warmed meta snapshots for period {$periodId} in ".round(microtime(true) - $start, 1).'s.');
        }

        return self::SUCCESS;
    }

    /**
     * The per-crawl summary line the ladder spec calls for — counters are
     * incremented by FetchLadderShard as shards complete.
     */
    private function logCrawlSummary(): void
    {
        foreach ([now()->format('Y-m-d'), now()->subDay()->format('Y-m-d')] as $day) {
            $fetched = (int) Cache::pull("ladder-crawl:{$day}:fetched", 0);
            $inserted = (int) Cache::pull("ladder-crawl:{$day}:inserted", 0);
            $skipped = (int) Cache::pull("ladder-crawl:{$day}:skipped", 0);
            $dispatched = (int) Cache::pull("ladder-crawl:{$day}:dispatched", 0);
            if ($fetched + $inserted + $skipped > 0) {
                $line = "Ladder crawl {$day}: {$fetched} shards fetched, {$inserted} runs inserted, {$skipped} duplicates skipped, {$dispatched} dispatched.";
                $this->info($line);
                Log::info($line);
            }
        }
    }
}
