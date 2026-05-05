<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterProfile;

class CharacterProfileMapper
{
    public function map(array $data): CharacterProfile
    {
        return new CharacterProfile(
            gender: $data['gender']['name'] ?? 'Unknown',
            faction: $data['faction']['name'] ?? 'Unknown',
            raceId: (int) ($data['race']['id'] ?? 0),
            classId: (int) ($data['character_class']['id'] ?? 0),
            level: (int) ($data['level'] ?? 0),
            achievementPoints: (int) ($data['achievement_points'] ?? 0),
            averageItemLevel: (int) ($data['average_item_level'] ?? 0),
            equippedItemLevel: (int) ($data['equipped_item_level'] ?? 0),
            guildName: $data['guild']['name'] ?? null,
            guildRealm: $data['guild']['realm']['slug'] ?? null,
            name: (string) ($data['name'] ?? ''),
            realmName: isset($data['realm']['name']) ? (string) $data['realm']['name'] : null,
            guildRealmName: isset($data['guild']['realm']['name']) ? (string) $data['guild']['realm']['name'] : null,
            activeSpecName: $data['active_spec']['name'] ?? null,
            activeSpecId: isset($data['active_spec']['id']) ? (int) $data['active_spec']['id'] : null,
            lastLoginTimestamp: isset($data['last_login_timestamp']) ? (int) $data['last_login_timestamp'] : null,
        );
    }
}
