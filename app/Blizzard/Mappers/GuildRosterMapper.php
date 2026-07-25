<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GuildMemberData;
use App\Support\BlizzardIdentity;

class GuildRosterMapper
{
    /**
     * @return GuildMemberData[]
     */
    public function map(array $data): array
    {
        $members = [];

        foreach ($data['members'] ?? [] as $entry) {
            $character = $entry['character'] ?? [];

            $rawName = (string) ($character['name'] ?? 'unknown');
            $rawRealmName = isset($character['realm']['name']) ? (string) $character['realm']['name'] : null;

            $members[] = new GuildMemberData(
                name: BlizzardIdentity::name($rawName),
                realm: $character['realm']['slug'] ?? 'unknown',
                level: (int) ($character['level'] ?? 0),
                classId: (int) ($character['playable_class']['id'] ?? 0),
                raceId: (int) ($character['playable_race']['id'] ?? 0),
                rank: (int) ($entry['rank'] ?? 0),
                displayName: $rawName,
                displayRealm: $rawRealmName,
            );
        }

        return $members;
    }
}
