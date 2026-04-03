<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GuildProfile;

class GuildProfileMapper
{
    public function map(array $data): GuildProfile
    {
        return new GuildProfile(
            faction: $data['faction']['name'] ?? 'Unknown',
            achievementPoints: (int) ($data['achievement_points'] ?? 0),
            memberCount: (int) ($data['member_count'] ?? 0),
            createdTimestamp: (int) ($data['created_timestamp'] ?? 0),
        );
    }
}
