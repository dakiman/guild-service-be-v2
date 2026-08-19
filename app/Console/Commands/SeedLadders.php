<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Jobs\FetchLadderShard;
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataPeriod;
use App\Services\RaiderIO\RaiderIOClient;
use App\Support\Seasons;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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
        {--dry-run : Count shards without dispatching}
        {--all-dungeons : Force the full game_data_mythic_keystone_dungeons sweep (no season pool)}';

    protected $description = 'Fan out FetchLadderShard jobs for every connected realm × current-season dungeon';

    public function handle(RaiderIOClient $raiderIO): int
    {
        if (! config('blizzard.mplus_leaderboard.enabled')) {
            $this->warn('Ladder crawl disabled (BLIZZARD_LADDER_ENABLED=false).');

            return self::SUCCESS;
        }

        $regions = $this->option('region') ?: config('blizzard.mplus_leaderboard.regions', ['eu', 'us']);

        // The season's dungeon pool is region-independent — resolve it once per run.
        $dungeonIds = $this->currentSeasonDungeonIds($raiderIO);
        if ($dungeonIds === []) {
            $this->error('No dungeon pool resolvable — aborting; tomorrow\'s crawl and the 48h finalize window self-heal. Use --all-dungeons to force the full-table sweep.');

            return self::FAILURE;
        }

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

                if (! $this->option('dry-run')) {
                    $key = 'ladder-crawl:'.now()->format('Y-m-d').':dispatched';
                    Cache::add($key, 0, now()->addHours(48));
                    Cache::increment($key, $dispatched);
                }
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

    /**
     * The current season's dungeon pool, from raider.io static-data.
     *
     * Blizzard's mythic-keystone season detail carries no `dungeons` key
     * (verified live: `_links, id, start_timestamp, periods, season_name`), so
     * the pool comes from raider.io instead. raider.io's `challenge_mode_id`
     * is the same id space as game_data_mythic_keystone_dungeons.id (it
     * already keys the icon backfill).
     *
     * @return list<int>
     */
    private function currentSeasonDungeonIds(RaiderIOClient $raiderIO): array
    {
        if ($this->option('all-dungeons')) {
            $this->warn('--all-dungeons: sweeping the full game_data_mythic_keystone_dungeons table.');

            return GameDataMythicKeystoneDungeon::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $season = Seasons::current();
        $slug = $season['slug'] ?? null;

        if ($slug === null) {
            $this->warn('No current season in game_data_seasons — cannot resolve the raider.io dungeon pool.');

            return [];
        }

        $cacheKey = "ladder:dungeon-pool:{$slug}";
        $expansionId = ($season['raiderio_expansion_id'] ?? 0) ?: (int) config('raiderio.expansion_id');

        try {
            $ids = $this->challengeModeIdsForSeason($raiderIO->mythicPlusStaticData($expansionId), $slug);
            if ($ids !== []) {
                Cache::put($cacheKey, $ids, now()->addDays(14));
                $this->info(sprintf('Dungeon pool for %s (raider.io expansion %d): %d dungeons.', $slug, $expansionId, count($ids)));

                return $ids;
            }
            $this->warn("raider.io static-data has no dungeons for season {$slug} (expansion {$expansionId}).");
        } catch (\Throwable $e) {
            $this->warn("Season dungeon pool lookup failed: {$e->getMessage()}");
        }

        $cached = Cache::get($cacheKey, []);
        if ($cached !== []) {
            $this->warn(sprintf('Using last-known-good pool for %s (%d dungeons).', $slug, count($cached)));

            return $cached;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function challengeModeIdsForSeason(array $payload, string $slug): array
    {
        foreach ($payload['seasons'] ?? [] as $entry) {
            if (($entry['slug'] ?? null) !== $slug) {
                continue;
            }

            return array_values(array_unique(array_filter(array_map(
                fn ($dungeon) => isset($dungeon['challenge_mode_id']) ? (int) $dungeon['challenge_mode_id'] : 0,
                $entry['dungeons'] ?? [],
            ))));
        }

        return [];
    }
}
