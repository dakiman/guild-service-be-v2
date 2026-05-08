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
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGuildData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 15;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        // Non-readonly with property-default so unserialize of old-shape queued
        // jobs gets `false` rather than "uninitialized" — see SyncCharacterData
        // forceTeammateCrawl for the same pattern + rationale.
        public bool $forceRosterFanout = false,
        // Set true by user-visit dispatch sites (GuildController, GuildService)
        // to force per-member Full fan-out + M+ teammate crawl on the resulting
        // SyncGuildRoster. Default false so background ProactiveSyncGuilds stays
        // Shallow-only.
        public bool $forceCascade = false,
    ) {
        $this->onQueue('blizzard-user-sync');
    }

    public function uniqueId(): string
    {
        // Mode segment so a queued auto-mode job (proactive sweep) doesn't
        // dedupe a force-mode job (user visit / seeder), which would silently
        // skip the per-member Full fan-out + teammate crawl. Two parallel jobs
        // for the same guild may run during a collision; both honor the rate
        // limiter and the cost is one redundant API round-trip.
        $mode = ($this->forceCascade || $this->forceRosterFanout) ? 'force' : 'auto';

        return "sync-guild:{$this->region}:{$this->realm}:{$this->name}:{$mode}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new BlizzardRateLimiter, new BlizzardHealthCheck];
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

        // Remove stale members not in roster
        $currentMemberNames = array_map(fn ($m) => $m->name, $members);
        $guild->members()
            ->whereNotIn('name', $currentMemberNames)
            ->delete();

        // Update roster sync timestamp
        $guild->update(['roster_synced_at' => now()]);

        // Dispatch the roster job — drives Shallow Bus::batch for all members AND
        // (when raiderio.dispatch_roster_character_syncs is true) Full per-member
        // SyncCharacterData fan-out. Previously gated on isRosterStale(), which is
        // always false here because we just set roster_synced_at to now() — the
        // gate was dead code, and the roster job never fired.
        SyncGuildRoster::dispatch($guild, $this->forceRosterFanout || $this->forceCascade);
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
