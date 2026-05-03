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

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
    ) {
        $this->onQueue('blizzard-user-sync');
    }

    public function uniqueId(): string
    {
        return "sync-guild:{$this->region}:{$this->realm}:{$this->name}";
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

        $memberRecords = [];
        foreach ($members as $member) {
            $memberRecords[] = [
                'guild_id' => $guild->id,
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

        // Upsert guild members
        if (! empty($memberRecords)) {
            GuildMember::upsert(
                $memberRecords,
                ['guild_id', 'name', 'realm'],
                ['level', 'class_id', 'race_id', 'rank', 'display_name', 'display_realm'],
            );
        }

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
        SyncGuildRoster::dispatch($guild);
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
