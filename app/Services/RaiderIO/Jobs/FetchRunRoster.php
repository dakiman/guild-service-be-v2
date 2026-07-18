<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Jobs;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use App\Services\RaiderIO\Middleware\RaiderIORateLimiter;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RunTeamPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchRunRoster implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $keystoneRunId,
        public readonly string $season,
        public readonly string $region,
    ) {
        $this->onQueue('raiderio-crawl');
    }

    public function uniqueId(): string
    {
        return "raiderio-roster:{$this->keystoneRunId}";
    }

    // Time-bound retries: RaiderIORateLimiter's release() re-queues without
    // burning a fixed $tries budget; only real exceptions ($maxExceptions)
    // cap the work. (P8, mirrors the Blizzard jobs' retryUntil contract.)
    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RaiderIORateLimiter];
    }

    public function handle(
        RaiderIOClient $client,
        RaiderIOMythicPlusMapper $mapper,
        RunTeamPersister $persister,
    ): void {
        $run = DungeonRun::where('keystone_run_id', $this->keystoneRunId)->first();
        if ($run === null) {
            return;
        }

        $detailsData = $client->getRunDetails($this->season, $this->keystoneRunId);
        $team = $mapper->mapRunDetailsRoster($detailsData);

        if ($team === []) {
            return;
        }

        $persister->syncTeam($run, $team, $this->region);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FetchRunRoster failed', [
            'keystone_run_id' => $this->keystoneRunId,
            'season' => $this->season,
            'error' => $exception->getMessage(),
        ]);
    }
}
