<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Blizzard\Mappers\CharacterEquipmentMapper;
use App\Blizzard\Mappers\CharacterMediaMapper;
use App\Blizzard\Mappers\CharacterProfessionMapper;
use App\Blizzard\Mappers\CharacterProfileMapper;
use App\Blizzard\Mappers\CharacterSpecializationMapper;
use App\Blizzard\Mappers\MythicPlusMapper;
use App\Blizzard\Mappers\MythicPlusRatingMapper;
use App\Blizzard\Mappers\PvpBracketStatsMapper;
use App\Blizzard\Mappers\RaidEncounterKillMapper;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\CharacterProfession;
use App\Models\CharacterPvpBracket;
use App\Models\DungeonRun;
use App\Models\Guild;
use App\Models\RaidEncounterKill;
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

class SyncCharacterData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        public readonly SyncDepth $depth = SyncDepth::Standard,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('blizzard-user-sync');
    }

    public function uniqueId(): string
    {
        return "sync-char:{$this->region}:{$this->realm}:{$this->name}:{$this->depth->value}";
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
        CharacterProfileMapper $profileMapper,
        CharacterMediaMapper $mediaMapper,
        CharacterEquipmentMapper $equipmentMapper,
        CharacterSpecializationMapper $specMapper,
        MythicPlusMapper $mythicPlusMapper,
        MythicPlusRatingMapper $ratingMapper,
        PvpBracketStatsMapper $pvpMapper,
        CharacterProfessionMapper $professionMapper,
        RaidEncounterKillMapper $raidMapper,
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
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
        ];

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

            if ($response['media']) {
                $media = $mediaMapper->map($response['media']);
                $characterData['media'] = [
                    'avatar' => $media->avatar,
                    'inset' => $media->inset,
                    'main' => $media->main,
                ];
            }

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
                $spec = $specMapper->map($response['specializations']);
                $characterData['active_specialization'] = $spec->activeSpecialization;
                $characterData['talent_loadout_code'] = $spec->talentLoadoutCode;
                $characterData['talents'] = [
                    'class' => $spec->classTalents,
                    'spec' => $spec->specTalents,
                    'hero' => $spec->heroTalents,
                    'pvp' => $spec->pvpTalents,
                ];
            }
        }

        // Upsert the character.
        // game_version is always 'retail' here — Classic uses a separate read-through
        // service and does not flow through this job.
        $character = Character::updateOrCreate(
            [
                'name' => $this->name,
                'realm' => $this->realm,
                'region' => $this->region,
                'game_version' => 'retail',
            ],
            $characterData,
        );

        // Link guild if present
        if ($profile->guildName && $profile->guildRealm) {
            $guild = Guild::firstOrCreate(
                [
                    'name' => $profile->guildName,
                    'realm' => $profile->guildRealm,
                    'region' => $this->region,
                ],
                [
                    'faction' => $profile->faction,
                ],
            );

            $character->update(['guild_id' => $guild->id]);
        }

        // Set user_id if provided
        if ($this->userId !== null) {
            $character->update(['user_id' => $this->userId]);
        }

        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
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

            foreach ($runs as $run) {
                $dungeonRun = DungeonRun::updateOrCreate(
                    [
                        'season' => $run->season,
                        'dungeon_id' => $run->dungeonId,
                        'completed_timestamp' => $run->completedTimestamp,
                    ],
                    [
                        'dungeon_name' => $run->dungeonName,
                        'keystone_level' => $run->keystoneLevel,
                        'duration' => $run->duration,
                        'is_completed_on_time' => $run->isCompletedOnTime,
                        'affixes' => $run->affixes,
                    ],
                );

                // Sync team members to dungeon run
                foreach ($run->team as $member) {
                    $memberCharacter = Character::where('name', strtolower($member['name']))
                        ->where('realm', $member['realm'])
                        ->where('region', $this->region)
                        ->first();

                    $dungeonRun->members()->syncWithoutDetaching([
                        $memberCharacter?->id ?? $character->id => [
                            'character_name' => $member['name'],
                            'character_realm' => $member['realm'],
                            'character_region' => $this->region,
                            'spec_name' => $member['specialization'],
                            'equipped_item_level' => $member['equipped_item_level'],
                        ],
                    ]);
                }
            }

            $rating = $ratingMapper->map($base, $seasonData, $this->name, $this->realm);
            $character->update([
                'mythic_plus_rating' => $rating->rating,
                'mythic_plus_rating_color' => $rating->color,
                'mythic_plus_rating_by_spec' => $rating->perSpec ?: null,
                'mythics_synced_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to sync mythic+ data for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
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
                $keep = [];
                foreach ($dtos as $dto) {
                    CharacterPvpBracket::updateOrCreate(
                        ['character_id' => $character->id, 'bracket' => $dto->bracket],
                        [
                            'rating' => $dto->rating,
                            'season_won' => $dto->seasonWon,
                            'season_lost' => $dto->seasonLost,
                            'season_played' => $dto->seasonPlayed,
                            'weekly_won' => $dto->weeklyWon,
                            'weekly_lost' => $dto->weeklyLost,
                            'weekly_played' => $dto->weeklyPlayed,
                            'tier_name' => $dto->tierName,
                        ],
                    );
                    $keep[] = $dto->bracket;
                }

                CharacterPvpBracket::where('character_id', $character->id)
                    ->when($keep !== [], fn ($q) => $q->whereNotIn('bracket', $keep))
                    ->delete();

                $character->update(['pvp_synced_at' => now()]);
            });
        } catch (Throwable $e) {
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
                $keep = [];
                foreach ($dtos as $dto) {
                    CharacterProfession::updateOrCreate(
                        [
                            'character_id' => $character->id,
                            'profession_id' => $dto->professionId,
                            'tier_name' => $dto->tierName,
                        ],
                        [
                            'profession_name' => $dto->professionName,
                            'skill_points' => $dto->skillPoints,
                            'max_skill_points' => $dto->maxSkillPoints,
                            'is_primary' => $dto->isPrimary,
                        ],
                    );
                    $keep[] = $dto->professionId.'|'.$dto->tierName;
                }

                CharacterProfession::where('character_id', $character->id)
                    ->get(['id', 'profession_id', 'tier_name'])
                    ->reject(fn ($row) => in_array($row->profession_id.'|'.$row->tier_name, $keep, true))
                    ->each(fn ($row) => CharacterProfession::whereKey($row->id)->delete());

                $character->update(['professions_synced_at' => now()]);
            });
        } catch (Throwable $e) {
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

            DB::transaction(function () use ($character, $dtos) {
                $keep = [];
                foreach ($dtos as $dto) {
                    RaidEncounterKill::updateOrCreate(
                        [
                            'character_id' => $character->id,
                            'encounter_id' => $dto->encounterId,
                            'difficulty' => $dto->difficulty,
                        ],
                        [
                            'expansion_name' => $dto->expansionName,
                            'instance_id' => $dto->instanceId,
                            'instance_name' => $dto->instanceName,
                            'encounter_name' => $dto->encounterName,
                            'completed_count' => $dto->completedCount,
                            'last_kill_timestamp' => $dto->lastKillTimestamp,
                        ],
                    );
                    $keep[] = $dto->encounterId.'|'.$dto->difficulty;
                }

                RaidEncounterKill::where('character_id', $character->id)
                    ->get(['id', 'encounter_id', 'difficulty'])
                    ->reject(fn ($row) => in_array($row->encounter_id.'|'.$row->difficulty, $keep, true))
                    ->each(fn ($row) => RaidEncounterKill::whereKey($row->id)->delete());

                $character->update(['raids_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Failed to sync raid encounters for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
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
