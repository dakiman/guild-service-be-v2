<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterReputation
{
    public function __construct(
        public int $factionId,
        public string $factionName,
        public string $standing,
        public int $value,
        public int $max,
    ) {}
}
