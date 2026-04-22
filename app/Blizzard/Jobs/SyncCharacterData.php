<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Mappers\CharacterEquipmentMapper;
use App\Blizzard\Mappers\CharacterMediaMapper;
use App\Blizzard\Mappers\CharacterProfileMapper;
use App\Blizzard\Mappers\CharacterSpecializationMapper;
use App\Blizzard\Mappers\MythicPlusMapper;
use App\Blizzard\Middleware\BlizzardHealthCheck;
use App\Blizzard\Middleware\BlizzardRateLimiter;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\Guild;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCharacterData implements ShouldQueue, ShouldBeUnique
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
        BlizzardGameDataClient $gameDataClient,
    ): void {
        $client = new BlizzardProfileClient($tokenManager, $this->region);

        $characterData = [
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
        ];

        if ($this->depth === SyncDepth::Shallow) {
            $response = $client->getCharacterData($this->realm, $this->name);
            $profile = $profileMapper->map($response['basic']);

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
            $response = $client->getCharacterData($this->realm, $this->name);
            $profile = $profileMapper->map($response['basic']);

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

        // Upsert the character
        $character = Character::updateOrCreate(
            [
                'name' => $this->name,
                'realm' => $this->realm,
                'region' => $this->region,
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
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $character);
        }
    }

    private function syncMythicPlus(
        BlizzardProfileClient $client,
        BlizzardGameDataClient $gameDataClient,
        MythicPlusMapper $mapper,
        Character $character,
    ): void {
        try {
            $season = $gameDataClient->getCurrentMythicPlusSeason();
            $data = $client->getCharacterMythicPlus($this->realm, $this->name, $season);
            $runs = $mapper->map($data, $season);

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

            $character->update(['mythics_synced_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Failed to sync mythic+ data for character', [
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
