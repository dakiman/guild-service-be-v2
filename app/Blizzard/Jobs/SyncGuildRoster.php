<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
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

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 60;

    /** Running index across BOTH the Shallow and Full fan-out loops. */
    private int $fanoutIndex = 0;

    public function __construct(
        public readonly Guild $guild,
        // Non-readonly so unserialize of old-shape payloads doesn't fatal on a
        // readonly write. Safe to read in handle() (unlike the "never read
        // post-rehydration" caveat on SyncGuildData/SyncCharacterData's newer
        // fields): $forceFanout predates this branch, so every payload still
        // in the queue already carries it — only fields added *after* a job
        // has queued instances in flight must not be read post-rehydration.
        public bool $forceFanout = false,
    ) {
        $this->onQueue('blizzard-roster-sync');
    }

    public function uniqueId(): string
    {
        // Mode segment so a queued auto-mode job (Shallow-only, dispatched
        // from a non-force SyncGuildData) doesn't dedupe a force-mode job
        // (the raider.io seeder), which would silently skip the per-member
        // Full SyncCharacterData fan-out + teammate crawl. Mirrors
        // SyncGuildData::uniqueId(); see commit 2e61a22.
        $mode = $this->forceFanout ? 'force' : 'auto';

        return "sync-guild-roster:{$this->guild->id}:{$mode}";
    }

    // Time-bound retries: every middleware release() re-queues without burning
    // a fixed $tries budget; only real exceptions (maxExceptions) cap the work.
    // 24h window (was 6h): with background sweeps gone, expiry means the queue
    // was genuinely wedged for a day — reaping is then the correct outcome.
    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
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

    public function handle(): void
    {
        // Fan-out is endgame-only: sub-max members stay roster rows (the guild
        // page reads guild_members directly) and get a Character row only if
        // someone looks them up.
        $members = $this->guild->members()
            ->where('level', '>=', (int) config('blizzard.endgame_level', 90))
            ->get();

        // Per-member SyncCharacterData::Full fan-out is gated solely by
        // forceFanout=true on this specific job (set only by the raider.io
        // seeder path via SyncGuildData::forceRosterFanout) — the only other
        // switch (raiderio.dispatch_roster_character_syncs) was dead config
        // (never set anywhere) and has been removed.
        $fullPathActive = $this->forceFanout;

        // When forceFanout is true (seeder run — currently the only caller
        // that dispatches this job at all), skip both Shallow and Full
        // dispatches for any member whose Character row is fresh (updated_at
        // within $ttl). Cold + stale members get a Full.
        // When forceFanout is false, every member gets a Shallow only —
        // $fullPathActive is false, so resolveFullTargets() below returns
        // no targets.
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

        // Members that will get a Full. Full is a strict superset of Shallow, so
        // these are skipped in the Shallow loop below — dispatching both wastes
        // API budget. (B1 behavior change: was "Shallow AND Full".)
        $fullTargets = $this->resolveFullTargets($members, $freshTuples, $fullPathActive);

        foreach ($members as $member) {
            $key = "{$member->name}|{$member->realm}";

            if ($this->forceFanout && isset($freshTuples[$key])) {
                continue;
            }

            if (isset($fullTargets[$key])) {
                continue;
            }

            $delay = $this->nextFanoutDelaySeconds();
            $pending = SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Shallow,
                origin: SyncOrigin::RosterFanout,
            );
            if ($delay > 0) {
                $pending->delay($delay);
            }
        }

        if ($fullPathActive) {
            $this->dispatchFullSyncsForMembers($members, $fullTargets);
        }
    }

    /**
     * Resolve the "name|realm" => true set of members that will receive a Full
     * sync. Empty when the full path is inactive.
     *
     * @param  array<string, true>  $freshTuples  force-path fresh-skip map (empty on the proactive path)
     * @return array<string, true>
     */
    protected function resolveFullTargets(Collection $members, array $freshTuples, bool $fullPathActive): array
    {
        if (! $fullPathActive) {
            return [];
        }

        $fullTargets = [];

        if ($this->forceFanout) {
            // Force path: every non-fresh member (fresh already skipped entirely).
            foreach ($members as $member) {
                $key = "{$member->name}|{$member->realm}";
                if (! isset($freshTuples[$key])) {
                    $fullTargets[$key] = true;
                }
            }

            return $fullTargets;
        }

        // Non-force path: per-member staleness lookup, run once here (hoisted
        // out of dispatchFullSyncsForMembers so it doesn't repeat). Currently
        // unreachable — $fullPathActive is now exactly $this->forceFanout, so
        // reaching this line implies forceFanout is true and the branch above
        // already returned. Kept as the structural fallback for a future
        // non-force Full-fanout switch (see the deleted raiderio config).
        $ttl = (int) config('raiderio.character_resync_ttl', 86400);
        $cutoff = now()->subSeconds($ttl);

        foreach ($members as $member) {
            $existing = Character::byIdentity($member->name, $member->realm, $this->guild->region)->first();
            if ($existing !== null && $existing->updated_at !== null && $existing->updated_at->isAfter($cutoff)) {
                continue;
            }
            $fullTargets["{$member->name}|{$member->realm}"] = true;
        }

        return $fullTargets;
    }

    /**
     * @param  array<string, true>  $fullTargets  precomputed "name|realm" => true set of Full recipients
     */
    protected function dispatchFullSyncsForMembers(Collection $members, array $fullTargets): void
    {
        foreach ($members as $member) {
            if (! isset($fullTargets["{$member->name}|{$member->realm}"])) {
                continue;
            }

            $delay = $this->nextFanoutDelaySeconds();
            $pending = SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Full,
                userId: null,
                crawlDepth: 0,
                forceTeammateCrawl: $this->forceFanout,
                origin: SyncOrigin::RosterFanout,
            );
            if ($delay > 0) {
                $pending->delay($delay);
            }
        }
    }

    /**
     * Progressive delay (seconds) for the next fan-out dispatch: job i runs
     * at ~i/jobs_per_minute minutes. retryUntil (24h) is stamped at dispatch,
     * so the stagger must stay well under it — at the default 30/min even a
     * 1000-member roster spreads over ~33 min.
     */
    private function nextFanoutDelaySeconds(): int
    {
        $perMinute = max(1, (int) config('blizzard.roster_fanout.jobs_per_minute', 30));

        return intdiv($this->fanoutIndex++ * 60, $perMinute);
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
