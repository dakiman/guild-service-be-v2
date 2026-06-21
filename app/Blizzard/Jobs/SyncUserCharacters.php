<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardUserClient;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncDepth;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncUserCharacters implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly User $user,
        public readonly string $region,
        public readonly string $accessToken,
    ) {
        $this->onQueue('blizzard-user-sync');
    }

    // Time-bound retries (mirrors SyncCharacterData): every middleware release()
    // re-queues without burning a fixed $tries budget; only real exceptions
    // (maxExceptions) cap the work, within a 6h window. (P1.10)
    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Health check before rate limiter: don't spend a throttle slot (and up
        // to a 30s block) only to discover the circuit is open. (P1.10)
        return [new BlizzardHealthCheck, new BlizzardRateLimiter];
    }

    public function handle(BlizzardUserClient $userClient): void
    {
        // Fetch user info from Battle.net
        $userInfo = $userClient->getUserInfo($this->region, $this->accessToken);

        // Update user bnet fields
        $this->user->update([
            'bnet_id' => $userInfo['id'] ?? $this->user->bnet_id,
            'bnet_tag' => $userInfo['battletag'] ?? $this->user->bnet_tag,
            'bnet_region' => $this->region,
            'bnet_synced_at' => now(),
        ]);

        // Fetch user characters
        $charactersData = $userClient->getUserCharacters($this->region, $this->accessToken);

        $accounts = $charactersData['wow_accounts'] ?? [];

        foreach ($accounts as $account) {
            $characters = $account['characters'] ?? [];

            foreach ($characters as $character) {
                $name = strtolower($character['name'] ?? '');
                $realm = $character['realm']['slug'] ?? '';

                if ($name === '' || $realm === '') {
                    continue;
                }

                SyncCharacterData::dispatch(
                    region: $this->region,
                    realm: $realm,
                    name: $name,
                    depth: SyncDepth::Full,
                    userId: $this->user->id,
                );
            }
        }

        $this->user->update(['bnet_sync_status' => null]);
    }

    public function failed(Throwable $exception): void
    {
        $this->user->update(['bnet_sync_status' => null]);

        Log::error('SyncUserCharacters failed', [
            'user_id' => $this->user->id,
            'region' => $this->region,
            'error' => $exception->getMessage(),
        ]);
    }
}
