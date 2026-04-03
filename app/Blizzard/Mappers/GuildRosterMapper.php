<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GuildMemberData;

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

            $members[] = new GuildMemberData(
                name: strtolower($character['name'] ?? 'unknown'),
                realm: $character['realm']['slug'] ?? 'unknown',
                level: (int) ($character['level'] ?? 0),
                classId: (int) ($character['playable_class']['id'] ?? 0),
                raceId: (int) ($character['playable_race']['id'] ?? 0),
                rank: (int) ($entry['rank'] ?? 0),
            );
        }

        return $members;
    }
}
