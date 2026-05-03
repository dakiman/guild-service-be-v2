<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GuildMemberData
{
    public function __construct(
        public string $name,
        public string $realm,
        public int $level,
        public int $classId,
        public int $raceId,
        public int $rank,
        public string $displayName,
        public ?string $displayRealm,
    ) {}
}
