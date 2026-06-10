<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GuildProfile
{
    public function __construct(
        public string $faction,
        public int $achievementPoints,
        public int $memberCount,
        public int $createdTimestamp,
        public string $name,
        public ?string $realmName,
    ) {}
}
