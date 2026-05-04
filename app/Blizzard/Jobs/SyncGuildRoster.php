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
        // Non-readonly with property-default so unserialize of old-shape queued
        // jobs gets `false` rather than "uninitialized".
        public bool $forceFanout = false,
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

        // When forceFanout is true (user-visit cascade or seeder run), skip both
        // Shallow and Full dispatches for any member whose Character row is fresh
        // (updated_at within $ttl). Cold + stale members get both Shallow and Full.
        // When forceFanout is false (proactive path), Shallow fires unconditionally
        // — same as today.
        $freshTuples = [];
        if ($this->forceFanout) {
            $ttl = (int) config('raiderio.character_resync_ttl', 86400);
            $cutoff = now()->subSeconds($ttl);

            $freshTuples = Character::query()
                ->where('region', $this->guild->region)
                ->where('game_version', 'retail')
                ->where('updated_at', '>', $cutoff)
                ->where(function ($q) use ($members) {
                    foreach ($members as $m) {
                        $q->orWhere(fn ($qq) => $qq->where('name', $m->name)->where('realm', $m->realm));
                    }
                })
                ->get(['name', 'realm'])
                ->mapWithKeys(fn ($c) => ["{$c->name}|{$c->realm}" => true])
                ->all();
        }

        foreach ($members as $member) {
            if ($this->forceFanout && isset($freshTuples["{$member->name}|{$member->realm}"])) {
                continue;
            }

            SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Shallow,
            );
        }

        // Per-member SyncCharacterData::Full fan-out is gated by either:
        // 1. forceFanout=true on this specific job (set by the seeder via SyncGuildData,
        //    or by the user-visit cascade via SyncGuildData::forceCascade).
        // 2. raiderio.dispatch_roster_character_syncs config flag (default false).
        if ($this->forceFanout || config('raiderio.dispatch_roster_character_syncs', false)) {
            $this->dispatchFullSyncsForMembers($members, $freshTuples);
        }
    }

    /**
     * @param  array<string, true>  $freshTuples  precomputed map of "name|realm" => true for members
     *                                            whose Character is fresh under the unified TTL gate.
     *                                            Empty when forceFanout was false (proactive path
     *                                            uses its own self-contained TTL gate below).
     */
    protected function dispatchFullSyncsForMembers(Collection $members, array $freshTuples = []): void
    {
        $ttl = (int) config('raiderio.character_resync_ttl', 86400);
        $cutoff = now()->subSeconds($ttl);

        foreach ($members as $member) {
            // Unified gate (already computed) takes precedence when forceFanout was true.
            if ($this->forceFanout && isset($freshTuples["{$member->name}|{$member->realm}"])) {
                continue;
            }

            // Proactive path (forceFanout=false, config flag=true) falls back to per-member lookup.
            if (! $this->forceFanout) {
                $existing = Character::byIdentity($member->name, $member->realm, $this->guild->region)->first();
                if ($existing !== null && $existing->updated_at !== null && $existing->updated_at->isAfter($cutoff)) {
                    continue;
                }
            }

            SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Full,
                userId: null,
                crawlDepth: 0,
                forceTeammateCrawl: $this->forceFanout,
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
