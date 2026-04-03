<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProactiveSyncCharacters implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $tier,
    ) {
        $this->onQueue('blizzard-background');
    }

    public function uniqueId(): string
    {
        return "proactive-sync-characters:tier-{$this->tier}";
    }

    public function handle(): void
    {
        $query = match ($this->tier) {
            1 => Character::where('num_of_searches', '>=', 5)
                ->where('last_searched_at', '>=', now()->subDays(7)),
            2 => Character::where('num_of_searches', '>=', 2)
                ->where('last_searched_at', '>=', now()->subDays(30)),
            default => Character::query()->whereRaw('1 = 0'), // empty result
        };

        $query->each(function (Character $character) {
            SyncCharacterData::dispatch(
                region: $character->region,
                realm: $character->realm,
                name: $character->name,
                depth: SyncDepth::Standard,
            );
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProactiveSyncCharacters failed', [
            'tier' => $this->tier,
            'error' => $exception->getMessage(),
        ]);
    }
}
