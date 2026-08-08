<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\GameDataClientFactory;
use App\Blizzard\Jobs\FetchLadderShard;
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataPeriod;
use Illuminate\Console\Command;

class SeedLadders extends Command
{
    /**
     * How long after a period's reset we keep re-crawling it. The last scheduled
     * crawl of a week runs hours before that week's reset, so without this window
     * every run completed between it and the reset would be missed permanently.
     */
    private const FINALIZE_WINDOW_HOURS = 48;

    protected $signature = 'blizzard:seed-ladders
        {--region=* : Limit to specific region(s)}
        {--period= : Blizzard period id override}
        {--dry-run : Count shards without dispatching}';

    protected $description = 'Fan out FetchLadderShard jobs for every connected realm × current-season dungeon';

    public function handle(GameDataClientFactory $clientFactory): int
    {
        if (! config('blizzard.mplus_leaderboard.enabled')) {
            $this->warn('Ladder crawl disabled (BLIZZARD_LADDER_ENABLED=false).');

            return self::SUCCESS;
        }

        $regions = $this->option('region') ?: config('blizzard.mplus_leaderboard.regions', ['eu', 'us']);

        foreach ($regions as $region) {
            $periodIds = $this->resolvePeriodIds($region);

            if ($periodIds === []) {
                $this->error("No current period known for {$region} — run `blizzard:sync-game-data periods` first.");

                continue;
            }

            $crIds = GameDataConnectedRealm::query()->where('region', $region)->pluck('connected_realm_id');
            if ($crIds->isEmpty()) {
                $this->error("No connected realms known for {$region} — run `blizzard:sync-game-data connected-realms` first.");

                continue;
            }

            $dungeonIds = $this->currentSeasonDungeonIds($clientFactory, $region);
            if ($dungeonIds === []) {
                $this->error("No dungeons resolvable for {$region}. Skipping.");

                continue;
            }

            foreach ($periodIds as $periodId) {
                $dispatched = 0;
                foreach ($crIds as $crId) {
                    foreach ($dungeonIds as $dungeonId) {
                        if (! $this->option('dry-run')) {
                            FetchLadderShard::dispatch($region, (int) $crId, (int) $dungeonId, $periodId);
                        }
                        $dispatched++;
                    }
                }

                $verb = $this->option('dry-run') ? 'Would dispatch' : 'Dispatched';
                $this->info("{$verb} {$dispatched} ladder shard jobs for {$region} period {$periodId} ({$crIds->count()} realms × ".count($dungeonIds).' dungeons).');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Periods to crawl for a region: the current one, plus a just-ended one so the
     * first pull after reset finalizes the completed week. Shard jobs dedupe on
     * run_hash (and are ShouldBeUnique per period), so the overlap is harmless.
     *
     * @return list<int>
     */
    private function resolvePeriodIds(string $region): array
    {
        if ($this->option('period') !== null) {
            return [(int) $this->option('period')];
        }

        $ids = [];

        $current = GameDataPeriod::currentFor($region);
        if ($current !== null) {
            $ids[] = $current->period_id;
        }

        $justEnded = GameDataPeriod::recentlyEndedFor($region, self::FINALIZE_WINDOW_HOURS);
        if ($justEnded !== null) {
            $ids[] = $justEnded->period_id;
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    private function currentSeasonDungeonIds(GameDataClientFactory $clientFactory, string $region): array
    {
        $client = $clientFactory->forRegion($region);

        try {
            $season = $client->getMythicKeystoneSeason($client->getCurrentMythicPlusSeason());
            $ids = array_values(array_filter(array_map(
                fn ($d) => isset($d['id']) ? (int) $d['id'] : null,
                $season['dungeons'] ?? [],
            )));
            if ($ids !== []) {
                return $ids;
            }
        } catch (\Throwable $e) {
            $this->warn("Season dungeon pool lookup failed for {$region}: {$e->getMessage()}");
        }

        $this->warn("Falling back to full game_data_mythic_keystone_dungeons table for {$region}.");

        return GameDataMythicKeystoneDungeon::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
