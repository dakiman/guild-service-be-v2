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
            try {
                foreach ($this->client->topGuilds($region, $opts->limit) as $ref) {
                    $report->considered++;

                    if (! $opts->force && $this->guildIsFresh($ref)) {
                        $report->skippedTtl++;

                        continue;
                    }

                    if ($opts->dryRun) {
                        $report->dispatched++;

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
                }
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

        Log::info('raiderio.seed.start', [
            'phase' => 'runs',
            'regions' => $opts->regions,
            'pages' => $opts->limit,
            'season' => $season,
        ]);

        foreach ($opts->regions as $region) {
            try {
                foreach ($this->client->topRuns($region, $season, $opts->limit) as $runRef) {
                    $report->considered++;

                    if ($opts->dryRun) {
                        // Dry-run does not mutate the ledger; check existence read-only.
                        if (SeededRun::where('keystone_run_id', $runRef->keystoneRunId)->exists()) {
                            $report->skippedDedupe++;

                            continue;
                        }
                    } else {
                        $inserted = DB::table('seeded_runs')->insertOrIgnore([
                            'keystone_run_id' => $runRef->keystoneRunId,
                            'region' => $region,
                            'seeded_at' => now(),
                        ]);

                        if ($inserted === 0) {
                            $report->skippedDedupe++;

                            continue;
                        }
                    }

                    foreach ($runRef->members as $memberRef) {
                        if (! $opts->force && $this->characterIsFresh($memberRef)) {
                            $report->skippedTtl++;

                            continue;
                        }

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
                        );
                        $report->dispatched++;
                    }
                }
            } catch (RaiderIOException $e) {
                $report->errors++;
                Log::warning('raiderio.seed.error', [
                    'phase' => 'runs',
                    'region' => $region,
                    'error' => $e->getMessage(),
                ]);
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
