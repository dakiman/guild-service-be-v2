<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\Guild;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGuildRoster implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 60;

    public function __construct(
        public readonly Guild $guild,
    ) {
        $this->onQueue('blizzard-roster-sync');
    }

    public function uniqueId(): string
    {
        return "sync-guild-roster:{$this->guild->id}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new BlizzardRateLimiter, new BlizzardHealthCheck];
    }

    public function handle(): void
    {
        $minLevel = (int) config('blizzard.min_level_for_character_lookup', 70);

        $members = $this->guild->members()
            ->where('level', '>=', $minLevel)
            ->get();

        $shallowJobs = $members
            ->map(fn ($member) => new SyncCharacterData(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Shallow,
            ))
            ->all();

        if (! empty($shallowJobs)) {
            Bus::batch($shallowJobs)
                ->allowFailures()
                ->name("guild-roster-sync:{$this->guild->id}")
                ->onQueue('blizzard-roster-sync')
                ->dispatch();
        }

        if (config('raiderio.dispatch_roster_character_syncs', false)) {
            $this->dispatchFullSyncsForMembers($members);
        }
    }

    protected function dispatchFullSyncsForMembers(Collection $members): void
    {
        $ttl = (int) config('raiderio.character_resync_ttl', 12 * 3600);
        $cutoff = now()->subSeconds($ttl);

        foreach ($members as $member) {
            $existing = Character::byIdentity($member->name, $member->realm, $this->guild->region)->first();
            if ($existing !== null && $existing->updated_at !== null && $existing->updated_at->isAfter($cutoff)) {
                continue;
            }

            SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Full,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncGuildRoster failed', [
            'guild_id' => $this->guild->id,
            'guild' => "{$this->guild->name}-{$this->guild->realm}-{$this->guild->region}",
            'error' => $exception->getMessage(),
        ]);
    }
}
