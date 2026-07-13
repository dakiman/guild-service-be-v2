<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGuildData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        // Non-readonly so unserialize of old-shape payloads doesn't fatal on a
        // readonly write. NOTE: the promoted default does NOT apply on
        // unserialize — an old payload leaves this property uninitialized, so
        // never read it in handle()/failed(); the queue lane was already fixed
        // at dispatch time. True only for the raider.io seeder: it opts in to
        // the SyncGuildRoster fan-out.
        public bool $forceRosterFanout = false,
        // Origin decides the queue lane — never infer routing from other params
        // (see SyncOrigin docblock; 2026-07-06 + 2026-07-12 incidents). Same
        // unserialize caveat as $forceRosterFanout above: an old payload leaves
        // this property uninitialized, so never read it post-rehydration; the
        // queue lane was already fixed at dispatch time.
        public SyncOrigin $origin = SyncOrigin::UserLookup,
    ) {
        $this->onQueue($origin->queue());
    }

    public function uniqueId(): string
    {
        // Mode segment so a queued auto-mode job (visit / auto-discover)
        // doesn't dedupe a force-mode job (seeder), which would silently skip
        // the per-member fan-out. Two parallel jobs for the same guild may run
        // during a collision; both honor the rate limiter and the cost is one
        // redundant API round-trip.
        $mode = $this->forceRosterFanout ? 'force' : 'auto';

        return "sync-guild:{$this->region}:{$this->realm}:{$this->name}:{$mode}";
    }

    /**
     * Horizon tags: make queue floods attributable to their origin in the
     * dashboard — mirrors SyncCharacterData::tags().
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            "origin:{$this->origin->value}",
            "guild:{$this->region}:{$this->realm}:{$this->name}",
        ];
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

    public function handle(
        TokenManagerInterface $tokenManager,
        GuildProfileMapper $profileMapper,
        GuildRosterMapper $rosterMapper,
    ): void {
        $client = new BlizzardProfileClient($tokenManager, $this->region);

        // Fetch guild data
        try {
            $guildData = $client->getGuildData($this->realm, $this->name);
        } catch (BlizzardNotFoundException) {
            Cache::put(
                "blizzard:not-found:guild:{$this->region}:{$this->realm}:{$this->name}",
                true,
                (int) config('blizzard.not_found_ttl', 86_400),
            );

            return;
        }

        $profile = $profileMapper->map($guildData);

        // Upsert guild
        $guild = Guild::updateOrCreate(
            [
                'name' => $this->name,
                'realm' => $this->realm,
                'region' => $this->region,
            ],
            [
                'faction' => $profile->faction,
                'achievement_points' => $profile->achievementPoints,
                'member_count' => $profile->memberCount,
                'created_timestamp' => $profile->createdTimestamp,
                'display_name' => $profile->name !== '' ? $profile->name : null,
                'display_realm' => $profile->realmName,
            ],
        );

        // Fetch and sync roster
        $rosterData = $client->getGuildRoster($this->realm, $this->name);
        $members = $rosterMapper->map($rosterData);

        // Pre-resolve character_id for each (name, realm) tuple so the upsert
        // can wire the FK in one round-trip per roster sync, avoiding the
        // GuildController stitch-by-tuple workaround.
        $charsByTuple = collect();
        if (! empty($members)) {
            $charsByTuple = Character::query()
                ->where('region', $this->region)
                ->where('game_version', 'retail')
                ->where(function ($q) use ($members) {
                    foreach ($members as $m) {
                        $q->orWhere(fn ($qq) => $qq->where('name', $m->name)->where('realm', $m->realm));
                    }
                })
                ->get(['id', 'name', 'realm'])
                ->keyBy(fn ($c) => $c->name.'|'.$c->realm);
        }

        $memberRecords = [];
        foreach ($members as $member) {
            $memberRecords[] = [
                'guild_id' => $guild->id,
                'character_id' => $charsByTuple["{$member->name}|{$member->realm}"]?->id ?? null,
                'name' => $member->name,
                'realm' => $member->realm,
                'level' => $member->level,
                'class_id' => $member->classId,
                'race_id' => $member->raceId,
                'rank' => $member->rank,
                'display_name' => $member->displayName,
                'display_realm' => $member->displayRealm,
            ];
        }

        // Atomic roster swap: upsert + backfill + prune + timestamp in one
        // transaction so a concurrent reader never sees a half-applied roster. (P1.4)
        DB::transaction(function () use ($guild, $memberRecords, $members) {
            // Upsert guild members.
            // character_id is intentionally OMITTED from the UPDATE column list:
            // INSERT still seeds it from the pre-resolve snapshot, but on conflict
            // we don't touch it. Otherwise a concurrent SyncCharacterData run that
            // called linkGuildMembers between our pre-resolve and this upsert could
            // have set character_id to a valid id, and our stale-snapshot null
            // would silently overwrite it. The post-upsert backfill below restores
            // any rows where pre-resolve missed but a Character now exists.
            if (! empty($memberRecords)) {
                GuildMember::upsert(
                    $memberRecords,
                    ['guild_id', 'name', 'realm'],
                    ['level', 'class_id', 'race_id', 'rank', 'display_name', 'display_realm'],
                );
            }

            // Post-upsert backfill: catches (a) Characters that appeared between
            // pre-resolve and the upsert above, and (b) Characters created since
            // the previous SyncGuildData run (the "Character appeared later" path
            // that the upsert update list used to cover, before that became
            // race-prone). Same query is also called by GuildController before
            // render — see Guild::backfillMemberCharacterIds().
            $guild->backfillMemberCharacterIds();

            // Remove stale members whose (name, realm) tuple is no longer in the
            // roster. Comparing name alone leaves a realm-transferred member as a
            // permanent duplicate (unique key is guild_id, name, realm). (P1.4)
            $rosterTuples = [];
            foreach ($members as $m) {
                $rosterTuples[$m->name.'|'.$m->realm] = true;
            }
            $staleIds = $guild->members()
                ->get(['id', 'name', 'realm'])
                ->reject(fn ($row) => isset($rosterTuples[$row->name.'|'.$row->realm]))
                ->pluck('id')
                ->all();
            if ($staleIds !== []) {
                $guild->members()->whereIn('id', $staleIds)->delete();
            }

            $guild->update(['roster_synced_at' => now()]);
        });

        // Roster fan-out is opt-in (raider.io seeder only). User visits and
        // auto-discover stop at profile + roster rows: members become full
        // characters when individually viewed. The unconditional dispatch here
        // is what amplified the 2026-07-12 queue flood — see
        // docs/superpowers/specs/2026-07-13-on-demand-guild-sync-design.md.
        if ($this->forceRosterFanout) {
            SyncGuildRoster::dispatch($guild, true);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncGuildData failed', [
            'region' => $this->region,
            'realm' => $this->realm,
            'name' => $this->name,
            'error' => $exception->getMessage(),
        ]);
    }
}
