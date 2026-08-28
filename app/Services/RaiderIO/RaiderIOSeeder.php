<?php

declare(strict_types=1);

namespace App\Services\RaiderIO;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Models\Guild;
use App\Models\SeededRun;
use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedReport;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\Exceptions\RaiderIOThrottledException;
use App\Support\Seasons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RaiderIOSeeder
{
    public function __construct(
        protected RaiderIOClient $client,
    ) {}

    public function seedGuilds(SeedOptions $opts): SeedReport
    {
        $report = new SeedReport(phase: 'guilds', regions: $opts->regions);

        Log::info('raiderio.seed.start', ['phase' => 'guilds', 'regions' => $opts->regions, 'limit' => $opts->limit]);

        foreach ($opts->regions as $region) {
            $regionDispatched = 0;

            try {
                foreach ($this->client->topGuilds($region, $opts->limit) as $ref) {
                    $report->considered++;

                    if (! $opts->force && $this->guildIsFresh($ref)) {
                        $report->skippedTtl++;

                        continue;
                    }

                    if ($opts->maxGuildDispatches > 0 && $regionDispatched >= $opts->maxGuildDispatches) {
                        $report->skippedCap++;

                        continue;
                    }

                    if ($opts->dryRun) {
                        $report->dispatched++;
                        $regionDispatched++;

                        continue;
                    }

                    SyncGuildData::dispatch(
                        $ref->region,
                        $ref->realmSlug,
                        $ref->name,
                        forceRosterFanout: true,
                        origin: SyncOrigin::Discovery,
                    );
                    $report->dispatched++;
                    $regionDispatched++;
                }
            } catch (RaiderIOThrottledException $e) {
                // Console context — blocking is fine here, unlike the crawl
                // jobs where a 429 must release() instead of sleeping a worker.
                sleep(min($e->retryAfter, 90));

                continue;
            } catch (RaiderIOException $e) {
                $report->errors++;
                Log::warning('raiderio.seed.error', [
                    'phase' => 'guilds',
                    'region' => $region,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('raiderio.seed.complete', $report->toArray());

        return $report;
    }

    public function seedRuns(SeedOptions $opts): SeedReport
    {
        $report = new SeedReport(phase: 'runs', regions: $opts->regions);
        $season = Seasons::raiderioSeasonSlug();

        // Per-dungeon ladders are additive breadth: the global top list is
        // meta-dungeon-biased, per-dungeon lists surface distinct rosters.
        $dungeons = [];
        if ($opts->dungeonPages > 0) {
            try {
                $dungeons = $this->client->seasonDungeonSlugs(
                    (int) config('raiderio.expansion_id'),
                    $season,
                );
            } catch (RaiderIOException $e) {
                $report->errors++;
                Log::warning('raiderio.seed.error', [
                    'phase' => 'runs',
                    'stage' => 'static-data',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('raiderio.seed.start', [
            'phase' => 'runs',
            'regions' => $opts->regions,
            'pages' => $opts->limit,
            'dungeon_pages' => $opts->dungeonPages,
            'dungeons' => $dungeons,
            'season' => $season,
        ]);

        foreach ($opts->regions as $region) {
            // One dispatch per member identity per invocation — the same top
            // players recur across ladders and cap slots must not be wasted.
            $dispatchedMembers = [];
            $regionDispatched = 0;
            $capReached = false;

            // null = the global ladder ($opts->limit pages), then one ladder
            // per dungeon ($opts->dungeonPages pages each).
            foreach ([null, ...$dungeons] as $dungeon) {
                if ($capReached) {
                    break;
                }
                $pages = $dungeon === null ? $opts->limit : $opts->dungeonPages;

                try {
                    foreach ($this->client->topRuns($region, $season, $pages, $dungeon) as $runRef) {
                        $report->considered++;

                        // The ledger is run-level dedupe only (run data is immutable).
                        // Members still go through the TTL gate below, so one missed on
                        // an earlier pass (queue hiccup, prior TTL) is picked up when
                        // the run reappears in the top list.
                        if ($opts->dryRun) {
                            // Dry-run does not mutate the ledger; check existence read-only.
                            if (SeededRun::where('keystone_run_id', $runRef->keystoneRunId)->exists()) {
                                $report->skippedDedupe++;
                            }
                        } else {
                            $inserted = DB::table('seeded_runs')->insertOrIgnore([
                                'keystone_run_id' => $runRef->keystoneRunId,
                                'region' => $region,
                                'seeded_at' => now(),
                            ]);

                            if ($inserted === 0) {
                                $report->skippedDedupe++;
                            }
                        }

                        foreach ($runRef->members as $memberRef) {
                            $memberKey = $memberRef->realmSlug.':'.$memberRef->name;
                            if (isset($dispatchedMembers[$memberKey])) {
                                $report->skippedDedupe++;

                                continue;
                            }

                            if (! $opts->force && $this->characterIsFresh($memberRef)) {
                                $report->skippedTtl++;

                                continue;
                            }

                            if ($opts->maxCharDispatches > 0 && $regionDispatched >= $opts->maxCharDispatches) {
                                $report->skippedCap++;
                                $capReached = true;

                                continue;
                            }

                            $dispatchedMembers[$memberKey] = true;
                            $regionDispatched++;

                            if ($opts->dryRun) {
                                $report->dispatched++;

                                continue;
                            }

                            SyncCharacterData::dispatch(
                                region: $memberRef->region,
                                realm: $memberRef->realmSlug,
                                name: $memberRef->name,
                                depth: SyncDepth::Full,
                                forceTeammateCrawl: $opts->teammateCrawl,
                                // Background lane, like the guild phase above. Left
                                // to the UserLookup default this put ~6.5k Full
                                // syncs/day in front of every interactive lookup.
                                origin: SyncOrigin::Discovery,
                            );
                            $report->dispatched++;
                        }

                        if ($capReached) {
                            // Abandon the region's remaining pages/ladders — no point
                            // spending raider.io requests on members we won't dispatch.
                            break;
                        }
                    }
                } catch (RaiderIOThrottledException $e) {
                    // Console context — blocking is fine here, unlike the crawl
                    // jobs where a 429 must release() instead of sleeping a worker.
                    sleep(min($e->retryAfter, 90));

                    continue;
                } catch (RaiderIOException $e) {
                    $report->errors++;
                    Log::warning('raiderio.seed.error', [
                        'phase' => 'runs',
                        'region' => $region,
                        'dungeon' => $dungeon,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('raiderio.seed.complete', $report->toArray());

        return $report;
    }

    protected function guildIsFresh(SeedGuildRef $ref): bool
    {
        $existing = Guild::byIdentity($ref->name, $ref->realmSlug, $ref->region)->first();

        return $existing !== null && ! $existing->isRosterStale();
    }

    protected function characterIsFresh(SeedCharacterRef $ref): bool
    {
        $existing = Character::byIdentity($ref->name, $ref->realmSlug, $ref->region)->first();
        if ($existing === null || $existing->updated_at === null) {
            return false;
        }
        $ttl = (int) config('raiderio.character_resync_ttl', 86400);

        return $existing->updated_at->isAfter(now()->subSeconds($ttl));
    }
}
