<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterProfile
{
    public function __construct(
        public string $gender,
        public string $faction,
        public int $raceId,
        public int $classId,
        public int $level,
        public int $achievementPoints,
        public int $averageItemLevel,
        public int $equippedItemLevel,
        public ?string $guildName,
        public ?string $guildRealm,
    ) {}
}
