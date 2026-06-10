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
            ->where('game_version', 'retail');

        if ($region = $this->option('region')) {
            $query->where('region', $region);
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $characters = $query->get(['region', 'realm', 'name']);

        if ($this->option('dry-run')) {
            $this->info("Dry run: would dispatch {$characters->count()} CrawlCharacterRuns jobs (season {$season}).");

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($characters as $character) {
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
