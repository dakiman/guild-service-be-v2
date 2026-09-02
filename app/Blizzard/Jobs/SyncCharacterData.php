<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\DTO\CharacterMythicPlusRating;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Blizzard\Mappers\CharacterAchievementMapper;
use App\Blizzard\Mappers\CharacterEquipmentMapper;
use App\Blizzard\Mappers\CharacterMediaMapper;
use App\Blizzard\Mappers\CharacterMountMapper;
use App\Blizzard\Mappers\CharacterPetMapper;
use App\Blizzard\Mappers\CharacterProfessionMapper;
use App\Blizzard\Mappers\CharacterProfileMapper;
use App\Blizzard\Mappers\CharacterReputationMapper;
use App\Blizzard\Mappers\CharacterSpecializationMapper;
use App\Blizzard\Mappers\CharacterStatsMapper;
use App\Blizzard\Mappers\CharacterTitleMapper;
use App\Blizzard\Mappers\CharacterToyMapper;
use App\Blizzard\Mappers\MythicPlusMapper;
use App\Blizzard\Mappers\MythicPlusRatingMapper;
use App\Blizzard\Mappers\PvpBracketStatsMapper;
use App\Blizzard\Mappers\RaidEncounterKillMapper;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Models\CharacterAchievement;
use App\Models\CharacterMount;
use App\Models\CharacterPet;
use App\Models\CharacterProfession;
use App\Models\CharacterPvpBracket;
use App\Models\CharacterToy;
use App\Models\DungeonRun;
use App\Models\GameDataTitle;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\RaidEncounterKill;
use App\Services\RunTeamPersister;
use App\Support\BlizzardIdentity;
use App\Support\RaidRetention;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCharacterData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    // Queue-lifetime dedupe: the unique lock is released when the job
    // completes or fails, so this TTL only bites for lost jobs (crashed
    // worker, queue flush) — matches the 24h retryUntil window.
    public int $uniqueFor = 86400;

    // Time-bound retries: every middleware release() re-queues without burning
    // a fixed $tries budget; only real exceptions (maxExceptions) cap the work.
    // 24h window (was 6h): with background sweeps gone, expiry means the queue
    // was genuinely wedged for a day — reaping is then the correct outcome.
    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        public readonly SyncDepth $depth = SyncDepth::Standard,
        public readonly ?int $userId = null,
        public readonly int $crawlDepth = 0,
        // Non-readonly so unserialize of an OLD-shape job (queued before this
        // param existed) doesn't fatal on a readonly write. NOTE: the
        // promoted default does NOT apply on unserialize — an old payload
        // leaves this property uninitialized (readonly props can't have
        // property-declaration defaults, and constructors don't run on
        // unserialize), so never read it post-rehydration.
        public bool $forceTeammateCrawl = false,
        // Same unserialize-safety pattern. Origin decides the queue lane —
        // never infer routing from crawlDepth/depth (that inference is how
        // roster fan-out flooded blizzard-user-sync on 2026-07-06). Same
        // caveat as $forceTeammateCrawl above: an old payload leaves this
        // property uninitialized, so never read it post-rehydration; the
        // queue lane was already fixed in the payload at dispatch time.
        public SyncOrigin $origin = SyncOrigin::UserLookup,
        // Force-refresh dedupe bypass (Plan 3): a caller-minted random token
        // that becomes part of uniqueId() below, so a `?refresh=1` dispatch is
        // never silently swallowed by ShouldBeUnique when an identical-shape
        // job is already in flight/uniqueFor window. Same unserialize-safety
        // pattern as $forceTeammateCrawl/$origin above — non-readonly,
        // uninitialized (not the promoted default) on an old-shape payload.
        // Never generate this value inside uniqueId() itself: that would mint
        // a different key on every call (Laravel calls uniqueId() again post
        // unserialize to release the lock) and leak the acquired lock.
        public ?string $refreshNonce = null,
    ) {
        $this->onQueue($origin->queue());
    }

    public function uniqueId(): string
    {
        // Mode segment so a queued non-crawl Full job doesn't dedupe a
        // forceTeammateCrawl=true job (seeder / user-visit cascade), which
        // would silently lose the crawl override. Mirrors SyncGuildData and
        // SyncGuildRoster — see commit 2e61a22.
        $mode = $this->forceTeammateCrawl ? 'force' : 'auto';

        $id = "sync-char:{$this->region}:{$this->realm}:{$this->name}:{$this->depth->value}:{$mode}";

        // isset() (not a null check) so an old-shape unserialized payload —
        // where $refreshNonce is uninitialized rather than null — falls
        // through to the exact legacy key instead of fataling on read.
        if (isset($this->refreshNonce)) {
            $id .= ":{$this->refreshNonce}";
        }

        return $id;
    }

    /**
     * Horizon tags: make queue floods attributable to their origin in the
     * dashboard (Monitoring → search "origin:roster-fanout").
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            "origin:{$this->origin->value}",
            "character:{$this->region}:{$this->realm}:{$this->name}",
        ];
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
        CharacterProfileMapper $profileMapper,
        CharacterMediaMapper $mediaMapper,
        CharacterEquipmentMapper $equipmentMapper,
        CharacterSpecializationMapper $specMapper,
        MythicPlusMapper $mythicPlusMapper,
        MythicPlusRatingMapper $ratingMapper,
        PvpBracketStatsMapper $pvpMapper,
        CharacterProfessionMapper $professionMapper,
        RaidEncounterKillMapper $raidMapper,
        CharacterStatsMapper $statsMapper,
        CharacterTitleMapper $titleMapper,
        CharacterReputationMapper $reputationMapper,
        CharacterMountMapper $mountMapper,
        CharacterPetMapper $petMapper,
        CharacterToyMapper $toyMapper,
        CharacterAchievementMapper $achievementMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
        $client = new BlizzardProfileClient($tokenManager, $this->region);

        try {
            $response = $client->getCharacterData($this->realm, $this->name);
        } catch (BlizzardNotFoundException) {
            Cache::put(
                "blizzard:not-found:character:{$this->region}:{$this->realm}:{$this->name}",
                true,
                (int) config('blizzard.not_found_ttl', 86_400),
            );

            return;
        }

        $profile = $profileMapper->map($response['basic']);

        $characterData = [
            // Canonical (mb-safe lowercase / slug) identity — must match the
            // updateOrCreate keys below, otherwise the update leg would write
            // the raw casing straight back over the canonical row.
            'name' => BlizzardIdentity::name($this->name),
            'realm' => BlizzardIdentity::realm($this->realm),
            'region' => $this->region,
            'display_name' => $profile->name !== '' ? $profile->name : null,
            'display_realm' => $profile->realmName,
            'last_login_at' => $profile->lastLoginTimestamp
                ? Carbon::createFromTimestampMs($profile->lastLoginTimestamp)
                : null,
        ];

        if ($response['media']) {
            $media = $mediaMapper->map($response['media']);
            $characterData['media'] = [
                'avatar' => $media->avatar,
                'inset' => $media->inset,
                'main' => $media->main,
            ];
        }

        if ($this->depth === SyncDepth::Shallow) {
            $characterData = array_merge($characterData, [
                'gender' => $profile->gender,
                'faction' => $profile->faction,
                'race_id' => $profile->raceId,
                'class_id' => $profile->classId,
                'level' => $profile->level,
                'achievement_points' => $profile->achievementPoints,
                'average_item_level' => $profile->averageItemLevel,
                'equipped_item_level' => $profile->equippedItemLevel,
                'active_specialization' => $profile->activeSpecName,
                'active_specialization_id' => $profile->activeSpecId,
            ]);
        } else {

            $characterData = array_merge($characterData, [
                'gender' => $profile->gender,
                'faction' => $profile->faction,
                'race_id' => $profile->raceId,
                'class_id' => $profile->classId,
                'level' => $profile->level,
                'achievement_points' => $profile->achievementPoints,
                'average_item_level' => $profile->averageItemLevel,
                'equipped_item_level' => $profile->equippedItemLevel,
            ]);

            if ($response['equipment']) {
                $equipment = $equipmentMapper->map($response['equipment']);
                $characterData['equipment'] = array_map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quality' => $item->quality,
                    'slot' => $item->slot,
                    'item_level' => $item->itemLevel,
                    'bonus' => $item->bonus,
                    'gems' => $item->gems,
                    'enchantments' => $item->enchantments,
                    'set_id' => $item->setId,
                    'stats' => $item->stats,
                ], $equipment);
            }

            if ($response['specializations']) {
                $regionGameData = new BlizzardGameDataClient($tokenManager, $this->region);
                $spec = $specMapper->map($response['specializations'], $regionGameData);
                $characterData['active_specialization'] = $spec->activeSpecialization;
                $characterData['active_specialization_id'] = $spec->activeSpecializationId;
                $characterData['talent_tree_id'] = $spec->talentTreeId;
                $characterData['talent_loadout_code'] = $spec->talentLoadoutCode;
                $characterData['talents'] = [
                    'class' => $spec->classTalents,
                    'spec' => $spec->specTalents,
                    'hero' => $spec->heroTalents,
                    'pvp' => $spec->pvpTalents,
                ];
            }
        }

        if ($response['mythic_keystone']) {
            $mythicRating = $ratingMapper->map(
                $response['mythic_keystone'], null, $this->name, $this->realm
            );
            $characterData = array_merge($characterData, $this->ratingColumns($mythicRating));
        }

        // Upsert the character.
        // game_version is always 'retail' here — Classic uses a separate read-through
        // service and does not flow through this job.
        // The identity keys are canonicalized here (not just at dispatch sites):
        // jobs serialized before the canonicalization deploy still carry raw-cased
        // names and unserialize bypasses the constructor, so this is the one
        // chokepoint that guarantees no non-canonical row can ever be written.
        $character = Character::updateOrCreate(
            [
                'name' => BlizzardIdentity::name($this->name),
                'realm' => BlizzardIdentity::realm($this->realm),
                'region' => $this->region,
                'game_version' => 'retail',
            ],
            $characterData,
        );

        self::linkGuildMembers($character);

        // Promote from any depth that doesn't already sync slices (Shallow,
        // Standard): covers the newly-dinged case where the lookup lane saw a
        // sub-endgame row and dispatched Standard — this sync just wrote the
        // real level, so escalate in the same pass. Full and StaleOnly are
        // exempt: StaleOnly already reads a null *_synced_at as stale and
        // syncs it in the fan-out below, so a separate promotion would just
        // double-dispatch the same work.
        if (! $this->depth->syncsSlices()
            && $character->level >= (int) config('blizzard.endgame_level', 90)
            && $character->mythics_synced_at === null
        ) {
            self::dispatch(
                region: $this->region,
                realm: $this->realm,
                name: $this->name,
                depth: SyncDepth::Full,
                forceTeammateCrawl: true,
                origin: $this->origin,
            );
        }

        // Link guild if present.
        // Canonicalize guildName the same way GuildController does
        // (BlizzardIdentity::realm via Str::slug) so character-side and
        // user-lookup-side writes converge on a single guild row.
        if ($profile->guildName && $profile->guildRealm) {
            $guildName = BlizzardIdentity::realm($profile->guildName);
            $guild = Guild::firstOrCreate(
                [
                    'name' => $guildName,
                    'realm' => $profile->guildRealm,
                    'region' => $this->region,
                ],
                [
                    'faction' => $profile->faction,
                    'display_name' => $profile->guildName,
                    'display_realm' => $profile->guildRealmName,
                ],
            );

            $character->update(['guild_id' => $guild->id]);

            // Newly-discovered guild gets a SyncGuildData dispatch so the
            // shell row populates profile + roster rows on the background
            // lane — no per-member fan-out — instead of waiting for the
            // user's first click. ShouldBeUnique on SyncGuildData dedupes
            // bursts when many characters in the same guild are synced
            // concurrently. Only fires on first creation; later visits go
            // through the normal stale-and-refresh path.
            if ($guild->wasRecentlyCreated) {
                SyncGuildData::dispatch(
                    $this->region,
                    $profile->guildRealm,
                    $guildName,
                    origin: SyncOrigin::Discovery,
                );
            }
        } else {
            // Character is guildless now — clear a stale link so ex-members stop
            // counting toward their old guild's stats. (P1.4)
            $character->update(['guild_id' => null]);
        }

        // Set user_id if provided
        if ($this->userId !== null) {
            $character->update(['user_id' => $this->userId]);
        }

        // Full/StaleOnly depth: also sync slices — but only at endgame level.
        // This is the invariant choke point: whatever lane dispatched a
        // slice-syncing depth (teammate crawl, seeder, a stale gate), a
        // sub-endgame profile gets no slice fan-out, checked against the
        // freshly fetched level. StaleOnly consults Character::is*Stale() at
        // EXECUTION TIME (no slice-set is ever serialized onto the job — a
        // dispatch-time set would go stale in queue) and syncs only what
        // reads stale right now; Full always syncs everything. Never-synced
        // slices have null *_synced_at, which every is*Stale() reads as
        // stale, so StaleOnly is equivalent to Full on first sync. Crawl
        // fan-out stays Full-only — StaleOnly is the economy path.
        if ($this->depth->syncsSlices() && $character->isEndgame()) {
            $all = $this->depth === SyncDepth::Full;
            if ($all || $character->isMythicsStale()) {
                $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            }
            if ($all || $character->isPvpStale()) {
                $this->syncPvpData($client, $pvpMapper, $character);
            }
            if ($all || $character->isProfessionsStale()) {
                $this->syncProfessions($client, $professionMapper, $character);
            }
            if ($all || $character->isRaidsStale()) {
                $this->syncRaidEncounters($client, $raidMapper, $character);
            }
            if ($all || $character->isStatsStale()) {
                $this->syncStats($client, $statsMapper, $character);
            }
            if ($all || $character->isTitlesStale()) {
                $this->syncTitles($client, $titleMapper, $character);
            }
            if ($all || $character->isReputationsStale()) {
                $this->syncReputations($client, $reputationMapper, $character);
            }
            if ($all || $character->isCollectionsStale()) {
                $this->syncCollections($client, $mountMapper, $petMapper, $toyMapper, $character);
            }
            if ($all || $character->isAchievementsStale()) {
                $this->syncAchievements($client, $achievementMapper, $character);
            }
            if ($all) {
                $this->dispatchTeammateCrawl($gameDataClient, $character);
            }
        }
    }

    private function syncMythicPlus(
        BlizzardProfileClient $client,
        BlizzardGameDataClient $gameData,
        MythicPlusMapper $mapper,
        MythicPlusRatingMapper $ratingMapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.mythic_plus_enabled')) {
            return;
        }

        try {
            $season = $gameData->getCurrentMythicPlusSeason();
            ['base' => $base, 'season' => $seasonData] = $client->getCharacterMythicPlusPool(
                $this->realm, $this->name, $season
            );
            $runs = $mapper->map($seasonData ?? [], $season);
            $rating = $ratingMapper->map($base, $seasonData, $this->name, $this->realm);

            DB::transaction(function () use ($runs, $rating, $character, $base) {
                foreach ($runs as $run) {
                    $dungeonRun = DungeonRun::upsertRun([
                        'season' => $run->season,
                        'dungeon_id' => $run->dungeonId,
                        'completed_timestamp' => $run->completedTimestamp,
                        'duration' => $run->duration,
                        'dungeon_name' => $run->dungeonName,
                        'keystone_level' => $run->keystoneLevel,
                        'is_completed_on_time' => $run->isCompletedOnTime,
                        'affixes' => $run->affixes,
                    ]);

                    $this->persistRunTeam($dungeonRun, $run->team);
                }

                $character->update(array_merge(
                    [
                        'mythic_plus_rating_by_spec' => $rating->perSpec ?: null,
                        'mythics_synced_at' => now(),
                    ],
                    // A 404 on the base profile must not wipe a stored rating —
                    // same contract as the profile-pool site.
                    $base === null ? [] : $this->ratingColumns($rating),
                ));
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync mythic+ data for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rating columns for a fetched (200) base M+ profile. Blizzard keeps
     * reporting the newest-played season's rating after a rollover, so the
     * rating is stored together with the season it belongs to and is only
     * cleared when the character has no M+ season history at all (a >0
     * rating with a null season would otherwise sit in the ratings:refresh
     * backlog forever). rating_synced_at means "base profile fetched at".
     *
     * @return array<string, mixed>
     */
    private function ratingColumns(CharacterMythicPlusRating $rating): array
    {
        $columns = [
            'rating_season_id' => $rating->seasonId,
            'rating_synced_at' => now(),
        ];

        if ($rating->rating !== null) {
            $columns['mythic_plus_rating'] = $rating->rating;
            $columns['mythic_plus_rating_color'] = $rating->color;
        } elseif ($rating->seasonId === null) {
            $columns['mythic_plus_rating'] = null;
            $columns['mythic_plus_rating_color'] = null;
        }

        return $columns;
    }

    /**
     * Persist a dungeon run's team via the query builder, bypassing
     * BelongsToMany::syncWithoutDetaching because the pivot's unique key is
     * (dungeon_run_id, character_name, character_realm, character_region) — not
     * character_id. Eloquent's pivot upsert is character_id-keyed, which silently
     * collapses unknown teammates onto a single row and trips SQLSTATE[23505]
     * when two synced characters share a run with an unknown member.
     *
     * Public so the regression tests in SyncMythicPlusTeamPivotTest can drive it.
     *
     * @param  array<int, array{name: string, realm: string, realm_name?: ?string, specialization_id?: ?int, specialization: ?string, equipped_item_level: ?int}>  $team
     */
    public function persistRunTeam(DungeonRun $run, array $team): void
    {
        app(RunTeamPersister::class)->syncTeam($run, $team, $this->region);
    }

    private function syncPvpData(
        BlizzardProfileClient $client,
        PvpBracketStatsMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.pvp_enabled')) {
            return;
        }

        try {
            $summary = $client->getCharacterPvpSummary($this->realm, $this->name);

            $slugs = [];
            foreach ($summary['brackets'] ?? [] as $entry) {
                $slug = $mapper->extractSlug((string) ($entry['href'] ?? ''));
                if ($slug !== null) {
                    $slugs[] = $slug;
                }
            }

            $bodies = $client->getCharacterPvpBracketsChunked($this->realm, $this->name, $slugs);
            $dtos = [];
            foreach ($bodies as $slug => $body) {
                $dto = $mapper->map($slug, $body);
                if ($dto !== null) {
                    $dtos[] = $dto;
                }
            }

            DB::transaction(function () use ($character, $dtos) {
                $rows = array_map(fn ($dto) => [
                    'character_id' => $character->id,
                    'bracket' => $dto->bracket,
                    'rating' => $dto->rating,
                    'season_won' => $dto->seasonWon,
                    'season_lost' => $dto->seasonLost,
                    'season_played' => $dto->seasonPlayed,
                    'weekly_won' => $dto->weeklyWon,
                    'weekly_lost' => $dto->weeklyLost,
                    'weekly_played' => $dto->weeklyPlayed,
                    'tier_name' => $dto->tierName,
                ], $dtos);

                CharacterPvpBracket::upsertMany($rows);

                $keep = array_column($dtos, 'bracket');
                CharacterPvpBracket::where('character_id', $character->id)
                    ->when($keep !== [], fn ($q) => $q->whereNotIn('bracket', $keep))
                    ->delete();

                $character->update(['pvp_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync pvp data for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncProfessions(
        BlizzardProfileClient $client,
        CharacterProfessionMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.professions_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterProfessions($this->realm, $this->name);
            $dtos = $mapper->map($data);

            DB::transaction(function () use ($character, $dtos) {
                $rows = array_map(fn ($dto) => [
                    'character_id' => $character->id,
                    'profession_id' => $dto->professionId,
                    'tier_name' => $dto->tierName,
                    'profession_name' => $dto->professionName,
                    'skill_points' => $dto->skillPoints,
                    'max_skill_points' => $dto->maxSkillPoints,
                    'is_primary' => $dto->isPrimary,
                    'expansion_id' => $dto->expansionId,
                ], $dtos);

                CharacterProfession::upsertMany($rows);

                // Composite key — collect stale ids in one query, delete in one shot.
                $keep = [];
                foreach ($dtos as $dto) {
                    $keep[$dto->professionId.'|'.$dto->tierName] = true;
                }
                $staleIds = CharacterProfession::where('character_id', $character->id)
                    ->get(['id', 'profession_id', 'tier_name'])
                    ->reject(fn ($row) => isset($keep[$row->profession_id.'|'.$row->tier_name]))
                    ->pluck('id')
                    ->all();
                if ($staleIds !== []) {
                    CharacterProfession::whereKey($staleIds)->delete();
                }

                $character->update(['professions_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync professions for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncRaidEncounters(
        BlizzardProfileClient $client,
        RaidEncounterKillMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.raids_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterRaidEncounters($this->realm, $this->name);
            $dtos = $mapper->map($data);

            // Background lanes only cache the current expansion (+ Current
            // Season): 99.99% of characters are crawl-discovered and their
            // legacy history exists only to bloat the table — it self-heals
            // via a user-lane Full sync the first time someone views them.
            // Null = current expansion unknown -> fail open, retain all.
            $retained = $this->origin === SyncOrigin::UserLookup ? null : RaidRetention::expansions();
            if ($retained !== null) {
                $dtos = array_values(array_filter(
                    $dtos,
                    fn ($dto) => in_array($dto->expansionName, $retained, true),
                ));
            }

            DB::transaction(function () use ($character, $dtos, $retained) {
                $rows = array_map(fn ($dto) => [
                    'character_id' => $character->id,
                    'encounter_id' => $dto->encounterId,
                    'difficulty' => $dto->difficulty,
                    'expansion_name' => $dto->expansionName,
                    'instance_id' => $dto->instanceId,
                    'instance_name' => $dto->instanceName,
                    'encounter_name' => $dto->encounterName,
                    'completed_count' => $dto->completedCount,
                    'last_kill_timestamp' => $dto->lastKillTimestamp,
                ], $dtos);

                RaidEncounterKill::upsertMany($rows);

                // Composite key — collect stale ids in one query, delete in one shot.
                $keep = [];
                foreach ($dtos as $dto) {
                    $keep[$dto->encounterId.'|'.$dto->difficulty] = true;
                }
                // Delete-missing must never reach beyond what this lane
                // persists: a gated sync scoped to retained expansions must
                // not wipe a searched character's legacy rows.
                $staleQuery = RaidEncounterKill::where('character_id', $character->id);
                if ($retained !== null) {
                    $staleQuery->whereIn('expansion_name', $retained);
                }
                $staleIds = $staleQuery
                    ->get(['id', 'encounter_id', 'difficulty'])
                    ->reject(fn ($row) => isset($keep[$row->encounter_id.'|'.$row->difficulty]))
                    ->pluck('id')
                    ->all();
                if ($staleIds !== []) {
                    RaidEncounterKill::whereKey($staleIds)->delete();
                }

                $character->update(['raids_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync raid encounters for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncStats(
        BlizzardProfileClient $client,
        CharacterStatsMapper $mapper,
        Character $character,
    ): void {
        try {
            $data = $client->getCharacterStats($this->realm, $this->name);

            DB::transaction(function () use ($character, $mapper, $data) {
                $character->update([
                    'stats' => $data === null ? null : $mapper->map($data)->fields,
                    'stats_synced_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync character stats', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncTitles(
        BlizzardProfileClient $client,
        CharacterTitleMapper $mapper,
        Character $character,
    ): void {
        try {
            $data = $client->getCharacterTitles($this->realm, $this->name);
            $result = $mapper->map($data);
            $dtos = $result['titles'];
            $activeTitleId = $result['activeTitleId'];

            DB::transaction(function () use ($character, $dtos, $activeTitleId) {
                $now = now();
                $titleIds = [];
                $rows = [];
                foreach ($dtos as $dto) {
                    $titleIds[] = $dto->titleId;
                    $rows[] = [
                        'id' => $dto->titleId,
                        'name_male' => $dto->name,
                        'name_female' => $dto->name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // insertOrIgnore (not upsert): create missing rows only, never
                // clobber the richer gendered names written by blizzard:sync-game-data
                // — mirrors the previous firstOrCreate semantics in one round-trip.
                if ($rows !== []) {
                    GameDataTitle::insertOrIgnore($rows);
                }

                $character->update([
                    'title_ids' => array_values($titleIds),
                    'active_title_id' => $activeTitleId !== null && in_array($activeTitleId, $titleIds, true)
                        ? $activeTitleId
                        : null,
                    'titles_synced_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync titles for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncReputations(
        BlizzardProfileClient $client,
        CharacterReputationMapper $mapper,
        Character $character,
    ): void {
        try {
            $data = $client->getCharacterReputations($this->realm, $this->name);
            $dtos = $mapper->map($data);

            $character->update([
                'reputations' => array_map(fn ($dto) => [
                    'faction_id' => $dto->factionId,
                    'faction_name' => $dto->factionName,
                    'standing' => $dto->standing,
                    'value' => $dto->value,
                    'max' => $dto->max,
                ], $dtos),
                'reputations_synced_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync reputations for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncCollections(
        BlizzardProfileClient $client,
        CharacterMountMapper $mountMapper,
        CharacterPetMapper $petMapper,
        CharacterToyMapper $toyMapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.mounts_enabled')
            && ! config('blizzard.sync.pets_enabled')
            && ! config('blizzard.sync.toys_enabled')) {
            $character->update(['collections_synced_at' => now()]);

            return;
        }

        try {
            $bodies = $client->getCharacterCollections($this->realm, $this->name);

            $mountDtos = $mountMapper->map($bodies['mounts']);
            $petDtos = $petMapper->map($bodies['pets']);
            $toyDtos = $toyMapper->map($bodies['toys']);

            DB::transaction(function () use ($character, $mountDtos, $petDtos, $toyDtos) {
                if (config('blizzard.sync.mounts_enabled')) {
                    $keepMounts = [];
                    foreach ($mountDtos as $dto) {
                        CharacterMount::updateOrCreate(
                            ['character_id' => $character->id, 'mount_id' => $dto->mountId],
                            ['name' => $dto->name, 'is_useable' => $dto->isUseable],
                        );
                        $keepMounts[] = $dto->mountId;
                    }
                    CharacterMount::where('character_id', $character->id)
                        ->when($keepMounts !== [], fn ($q) => $q->whereNotIn('mount_id', $keepMounts))
                        ->delete();
                }

                if (config('blizzard.sync.pets_enabled')) {
                    $keepPets = [];
                    foreach ($petDtos as $dto) {
                        CharacterPet::updateOrCreate(
                            ['character_id' => $character->id, 'pet_id' => $dto->petId],
                            [
                                'species_id' => $dto->speciesId,
                                'name' => $dto->name,
                                'level' => $dto->level,
                                'breed_id' => $dto->breedId,
                                'quality' => $dto->quality,
                                'is_favorite' => $dto->isFavorite,
                                'creature_display_id' => $dto->creatureDisplayId,
                            ],
                        );
                        $keepPets[] = $dto->petId;
                    }
                    CharacterPet::where('character_id', $character->id)
                        ->when($keepPets !== [], fn ($q) => $q->whereNotIn('pet_id', $keepPets))
                        ->delete();
                }

                if (config('blizzard.sync.toys_enabled')) {
                    $keepToys = [];
                    foreach ($toyDtos as $dto) {
                        CharacterToy::updateOrCreate(
                            ['character_id' => $character->id, 'toy_id' => $dto->toyId],
                            ['name' => $dto->name],
                        );
                        $keepToys[] = $dto->toyId;
                    }
                    CharacterToy::where('character_id', $character->id)
                        ->when($keepToys !== [], fn ($q) => $q->whereNotIn('toy_id', $keepToys))
                        ->delete();
                }

                $character->update(['collections_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync collections for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncAchievements(
        BlizzardProfileClient $client,
        CharacterAchievementMapper $mapper,
        Character $character,
    ): void {
        // Feature-gated: achievements are expensive (≈70% of DB); off by default.
        // Stamp the timestamp anyway (mirrors syncCollections): leaving it null
        // keeps isAchievementsStale() true forever, so every lookup would
        // re-dispatch a Full sync and burn the rate budget.
        if (! config('blizzard.sync.achievements_enabled')) {
            $character->update(['achievements_synced_at' => now()]);

            return;
        }

        try {
            $data = $client->getCharacterAchievements($this->realm, $this->name);
            $dtos = $mapper->map($data);

            DB::transaction(function () use ($character, $dtos) {
                // DELETE-then-bulk-INSERT: cheaper than O(N) updateOrCreate + per-row delete
                // for 30k+ row payloads. Achievements are append-only so per-row diff
                // semantics buy nothing.
                CharacterAchievement::where('character_id', $character->id)->delete();

                if ($dtos !== []) {
                    $now = now();
                    $rows = array_map(fn ($dto) => [
                        'character_id' => $character->id,
                        'achievement_id' => $dto->achievementId,
                        'completed_timestamp' => $dto->completedTimestamp,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $dtos);

                    // PostgreSQL parameter ceiling is 65535; at 5 cols/row, 1000 rows = 5000
                    // placeholders, well under the limit.
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        CharacterAchievement::insert($chunk);
                    }
                }

                $character->update(['achievements_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            $this->rethrowIfRateLimited($e);
            Log::warning('Failed to sync achievements for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recursive fan-out: dispatch a Standard-depth sync for each Mythic+
     * teammate of the seed we just synced. Gated on
     * BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED, capped by BLIZZARD_CRAWL_MAX_DEPTH
     * (hard-clamped to 2), and skips teammates whose updated_at is within
     * BLIZZARD_CRAWL_RECENT_THRESHOLD seconds.
     *
     * Runs at the end of handle() — after every slice has committed — so
     * a dispatch failure here never rolls back persisted seed data.
     */
    private function dispatchTeammateCrawl(
        BlizzardGameDataClient $gameDataClient,
        Character $character,
    ): void {
        // Phase 2: a seed-originated job (forceTeammateCrawl=true) overrides the
        // global kill-switch. Crawled descendants get forceTeammateCrawl=false at
        // the self::dispatch call below — the override does not recurse, so nested
        // crawls fall back to the global config flag.
        if (! $this->forceTeammateCrawl && ! config('blizzard.sync.teammate_crawl_enabled')) {
            return;
        }

        $maxDepth = (int) config('blizzard.crawl.max_depth', 1);
        // Hard ceiling: never honor max_depth > 2 even if env says so.
        $maxDepth = min($maxDepth, 2);

        if ($this->crawlDepth >= $maxDepth) {
            return;
        }

        try {
            $season = $gameDataClient->getCurrentMythicPlusSeason();

            // Plan-proof lookup — see Character::seasonTeammateRows() for why
            // this must not be a single season-filtered join.
            $rows = $character->seasonTeammateRows((int) $season);

            $threshold = (int) config('blizzard.crawl.recent_threshold', 21600);
            $cutoff = now()->subSeconds($threshold);

            // First pass: dedupe and drop the seed / blank identities.
            $targets = [];
            foreach ($rows as $row) {
                // Pivot names are display-cased by design (RunTeamPersister); strtolower()
                // is ASCII-only and would leave multibyte names capitalized.
                $name = BlizzardIdentity::name((string) $row->character_name);
                $realm = (string) $row->character_realm;
                $region = (string) $row->character_region;
                $key = "{$region}:{$realm}:{$name}";

                if (isset($targets[$key])) {
                    continue;
                }

                // Skip the seed itself.
                if ($name === BlizzardIdentity::name($this->name)
                    && $realm === $this->realm
                    && $region === $this->region) {
                    continue;
                }

                // Defensive — pivot is NOT NULL on all three identity cols.
                if ($name === '' || $realm === '' || $region === '') {
                    continue;
                }

                $targets[$key] = ['region' => $region, 'realm' => $realm, 'name' => $name];
            }

            // Batched freshness lookup: one query instead of one SELECT per teammate.
            // Character has no synced_at column; isStale() consults updated_at, so we
            // use updated_at as the freshness signal here too. The three-column
            // whereIn may over-fetch unrelated rows, but the exact-key match below
            // filters them out, and names are stored canonical-lowercase.
            $freshKeys = [];
            if ($targets !== []) {
                Character::query()
                    ->where('game_version', 'retail')
                    ->whereIn('name', array_values(array_unique(array_column($targets, 'name'))))
                    ->whereIn('realm', array_values(array_unique(array_column($targets, 'realm'))))
                    ->whereIn('region', array_values(array_unique(array_column($targets, 'region'))))
                    ->get(['name', 'realm', 'region', 'updated_at'])
                    ->each(function ($c) use ($cutoff, &$freshKeys) {
                        if ($c->updated_at && $c->updated_at->greaterThan($cutoff)) {
                            $freshKeys["{$c->region}:{$c->realm}:{$c->name}"] = true;
                        }
                    });
            }

            $cap = max(0, (int) config('blizzard.crawl.max_teammates_per_seed', 10));

            $dispatched = 0;
            $skippedFresh = 0;
            $skippedCap = 0;
            foreach ($targets as $key => $t) {
                if (isset($freshKeys[$key])) {
                    $skippedFresh++;

                    continue;
                }

                if ($dispatched >= $cap) {
                    $skippedCap++;

                    continue;
                }

                self::dispatch(
                    region: $t['region'],
                    realm: $t['realm'],
                    name: $t['name'],
                    depth: SyncDepth::Full,
                    crawlDepth: $this->crawlDepth + 1,
                    origin: SyncOrigin::TeammateCrawl,
                );
                $dispatched++;
            }

            Log::info('Teammate crawl dispatched', [
                'seed' => "{$this->name}-{$this->realm}-{$this->region}",
                'seed_crawl_depth' => $this->crawlDepth,
                'teammates_dispatched' => $dispatched,
                'teammates_skipped_fresh' => $skippedFresh,
                'teammates_skipped_cap' => $skippedCap,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch teammate crawl', [
                'seed' => "{$this->name}-{$this->realm}-{$this->region}",
                'crawl_depth' => $this->crawlDepth,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Backfill GuildMember.character_id for any rows whose (name, realm, guild.region)
     * matches the given character. Idempotent: only fills NULLs, never overwrites.
     * Public-static so it can be unit-tested without driving the full handle() path.
     */
    public static function linkGuildMembers(Character $character): void
    {
        GuildMember::query()
            ->where('name', $character->name)
            ->where('realm', $character->realm)
            ->whereNull('character_id')
            ->whereHas('guild', fn ($q) => $q->where('region', $character->region))
            ->update(['character_id' => $character->id]);
    }

    private function rethrowIfRateLimited(Throwable $e): void
    {
        if ($e instanceof RequestException && $e->response?->status() === 429) {
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncCharacterData failed', [
            'region' => $this->region,
            'realm' => $this->realm,
            'name' => $this->name,
            'depth' => $this->depth->value,
            'error' => $exception->getMessage(),
        ]);
    }
}
