<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Models\Guild;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProactiveSyncGuilds implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('blizzard-background');
    }

    public function uniqueId(): string
    {
        return 'proactive-sync-guilds';
    }

    public function handle(): void
    {
        $staleThreshold = now()->subSeconds(
            (int) config('blizzard.staleness.guild.roster', 7200)
        );

        Guild::where(function ($query) use ($staleThreshold) {
            $query->whereNull('roster_synced_at')
                ->orWhere('roster_synced_at', '<', $staleThreshold);
        })
            ->each(function (Guild $guild) {
                SyncGuildData::dispatch(
                    region: $guild->region,
                    realm: $guild->realm,
                    name: $guild->name,
                );
            });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProactiveSyncGuilds failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
