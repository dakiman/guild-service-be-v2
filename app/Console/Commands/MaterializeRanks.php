<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameDataPeriod;
use App\Services\Ranks\RankMaterializer;
use App\Services\Ranks\RealmRunBoardBuilder;
use App\Services\Ranks\RealmSlugMapBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MaterializeRanks extends Command
{
    protected $signature = 'ranks:materialize {--dry-run : Count the population without writing}';

    protected $description = 'Rebuild character_ranks (world/region/realm/class/spec) and realm_run_boards from season-fresh ratings';

    public function handle(RankMaterializer $ranks, RealmSlugMapBuilder $slugMap, RealmRunBoardBuilder $boards): int
    {
        $season = $ranks->seasonStart();
        if ($season === null) {
            $this->error('No current season with started_at in game_data_seasons — nothing to rank.');

            return self::FAILURE;
        }

        $start = microtime(true);
        $mapped = $slugMap->rebuild();
        $this->info("Realm slug map: {$mapped} slugs.");

        if ($this->option('dry-run')) {
            $this->info($ranks->populationCount().' characters would be ranked for season '.$season['season_id'].'.');

            return self::SUCCESS;
        }

        $computedAt = now();
        $result = $ranks->materialize($computedAt);
        $seconds = round(microtime(true) - $start, 1);

        $perRegion = collect($result['per_region'])->map(fn ($n, $r) => "{$r}={$n}")->implode(', ');
        $this->info("Ranked {$result['ranked']} characters ({$perRegion}; {$result['unmapped']} on unmapped realms) in {$seconds}s.");

        $boardsWritten = 0;
        foreach ((array) config('blizzard.mplus_leaderboard.regions', ['eu', 'us']) as $region) {
            $period = GameDataPeriod::currentFor($region);
            if ($period === null) {
                $this->warn("No current period for {$region} — realm run boards skipped.");

                continue;
            }
            $n = $boards->build($region, $period->period_id, $computedAt);
            $boardsWritten += $n;
            $this->info("Realm run boards for {$region} period {$period->period_id}: {$n} realms.");
        }
        $result['boards'] = $boardsWritten;

        Log::info('ranks:materialize', $result + ['seconds' => $seconds]);

        return self::SUCCESS;
    }
}
