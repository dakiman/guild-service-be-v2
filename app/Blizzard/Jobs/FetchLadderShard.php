<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Client\GameDataClientFactory;
use App\Blizzard\Mappers\BlizzardLadderMapper;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Blizzard\Services\LadderRunPersister;
use App\Models\GameDataMythicKeystoneDungeon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchLadderShard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $region,
        public readonly int $connectedRealmId,
        public readonly int $dungeonId,
        public readonly int $periodId,
    ) {
        $this->onQueue('blizzard-ladder');
    }

    public function uniqueId(): string
    {
        return "ladder:{$this->region}:{$this->connectedRealmId}:{$this->dungeonId}:{$this->periodId}";
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new BlizzardHealthCheck, new BlizzardRateLimiter];
    }

    public function handle(GameDataClientFactory $clients, BlizzardLadderMapper $mapper, LadderRunPersister $persister): void
    {
        $payload = $clients->forRegion($this->region)
            ->getMythicLeaderboard($this->connectedRealmId, $this->dungeonId, $this->periodId);

        $this->bumpCounter('fetched');

        if ($payload === null) {
            return; // 404 — shard has no runs yet this period
        }

        $upgrades = GameDataMythicKeystoneDungeon::query()->find($this->dungeonId)?->keystone_upgrades;
        $mapped = $mapper->mapLeaderboard($payload, $this->periodId, $this->region, $this->dungeonId, $upgrades);
        $result = $persister->persist($mapped);

        $this->bumpCounter('inserted', $result['inserted']);
        $this->bumpCounter('skipped', $result['skipped']);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FetchLadderShard failed', [
            'region' => $this->region,
            'connected_realm_id' => $this->connectedRealmId,
            'dungeon_id' => $this->dungeonId,
            'period_id' => $this->periodId,
            'message' => $exception->getMessage(),
        ]);
    }

    private function bumpCounter(string $kind, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }
        $key = 'ladder-crawl:'.now()->format('Y-m-d').":{$kind}";
        Cache::add($key, 0, now()->addHours(48));
        Cache::increment($key, $by);
    }
}
