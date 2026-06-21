<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Jobs;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RunTeamPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrawlCharacterRuns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        public readonly int $season,
    ) {
        $this->onQueue('raiderio-crawl');
    }

    public function uniqueId(): string
    {
        return "raiderio-crawl:{$this->region}:{$this->realm}:{$this->name}";
    }

    public function handle(
        RaiderIOClient $client,
        RaiderIOMythicPlusMapper $mapper,
        RunTeamPersister $persister,
    ): void {
        $profileData = $client->getCharacterMythicPlusRuns($this->region, $this->realm, $this->name);
        $runs = $mapper->mapCharacterProfileRuns($profileData, $this->season);

        foreach ($runs as $run) {
            $dungeonRun = DungeonRun::upsertRun([
                'season' => $run->season,
                'dungeon_id' => $run->dungeonId,
                'completed_timestamp' => $run->completedTimestamp,
                'duration' => $run->duration,
                'keystone_run_id' => $run->keystoneRunId,
                'dungeon_name' => $run->dungeonName,
                'keystone_level' => $run->keystoneLevel,
                'is_completed_on_time' => $run->isCompletedOnTime,
                'affixes' => $run->affixes,
                'raiderio_score' => $run->score,
                'raiderio_url' => $run->url,
            ]);

            $this->addQueriedCharacterAsMember($dungeonRun, $profileData, $persister);
            $this->dispatchRosterFetchIfNeeded($dungeonRun, $run->keystoneRunId);
        }
    }

    private function addQueriedCharacterAsMember(DungeonRun $run, array $profileData, RunTeamPersister $persister): void
    {
        // Skip if the roster fetch already seated this player (display-cased):
        // re-adding the lowercase queried name would create a duplicate the
        // case-sensitive unique index can't catch, and that 6th member would
        // suppress the roster fetch that heals it. (P1.3)
        if ($persister->hasMember($run, $this->name, $this->realm, $this->region)) {
            return;
        }

        $firstRun = ($profileData['mythic_plus_recent_runs'] ?? $profileData['mythic_plus_best_runs'] ?? $profileData['mythic_plus_highest_level_runs'] ?? [])[0] ?? null;

        $persister->upsertMember($run, [
            'name' => $this->name,
            'realm' => $this->realm,
            'realm_name' => null,
            'specialization_id' => $firstRun['spec']['id'] ?? null,
            'specialization' => $firstRun['spec']['name'] ?? null,
            'equipped_item_level' => null,
        ], $this->region);
    }

    private function dispatchRosterFetchIfNeeded(DungeonRun $run, int $keystoneRunId): void
    {
        $memberCount = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->count();

        if ($memberCount < 5) {
            FetchRunRoster::dispatch(
                $keystoneRunId,
                (string) config('raiderio.season'),
                $this->region,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CrawlCharacterRuns failed', [
            'region' => $this->region,
            'realm' => $this->realm,
            'name' => $this->name,
            'error' => $exception->getMessage(),
        ]);
    }
}
