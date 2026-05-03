<?php

declare(strict_types=1);

namespace App\Services\RaiderIO;

use App\Blizzard\Jobs\SyncGuildData;
use App\Models\Guild;
use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedReport;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
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

                    SyncGuildData::dispatch($ref->region, $ref->realmSlug, $ref->name);
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

    protected function guildIsFresh(SeedGuildRef $ref): bool
    {
        $existing = Guild::byIdentity($ref->name, $ref->realmSlug, $ref->region)->first();

        return $existing !== null && ! $existing->isRosterStale();
    }
}
