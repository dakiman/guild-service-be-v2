<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\Character;
use App\Services\RaiderIO\Jobs\CrawlCharacterRuns;
use Illuminate\Console\Command;

class CrawlMythicPlusRuns extends Command
{
    protected $signature = 'raiderio:crawl-runs
        {--region= : Limit to a specific region}
        {--limit= : Max characters to dispatch}
        {--dry-run : Report counts without dispatching}';

    protected $description = 'Crawl raider.io for Mythic+ run data for tracked characters';

    public function handle(BlizzardGameDataClient $gameData): int
    {
        if (! config('raiderio.crawl.enabled')) {
            $this->warn('Raider.io crawl is disabled (RAIDERIO_CRAWL_ENABLED=false).');

            return self::SUCCESS;
        }

        $season = $gameData->getCurrentMythicPlusSeason();

        $query = Character::query()
            ->where('mythic_plus_rating', '>', 0)
            ->where('game_version', 'retail')
            // Deterministic order: highest-rated first, id as a stable tiebreak,
            // so --limit always picks the same (highest-value) subset. (P2.5)
            ->orderByDesc('mythic_plus_rating')
            ->orderBy('id');

        if ($region = $this->option('region')) {
            $query->where('region', $region);
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($this->option('dry-run')) {
            // count() aggregates to a single row, so the limit above does not
            // constrain it — clamp the report to the limit ourselves.
            $count = $query->count();
            $toDispatch = $limit !== null ? min($count, $limit) : $count;
            $this->info("Dry run: would dispatch {$toDispatch} CrawlCharacterRuns jobs (season {$season}).");

            return self::SUCCESS;
        }

        // Stream matched rows instead of loading the whole table into memory.
        $dispatched = 0;
        foreach ($query->select(['region', 'realm', 'name'])->cursor() as $character) {
            CrawlCharacterRuns::dispatch(
                $character->region,
                $character->realm,
                $character->name,
                $season,
            );
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} CrawlCharacterRuns jobs (season {$season}).");

        return self::SUCCESS;
    }
}
