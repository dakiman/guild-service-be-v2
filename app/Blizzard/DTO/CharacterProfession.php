<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterProfession
{
    public function __construct(
        public int $professionId,
        public string $professionName,
        public string $tierName,
        public int $skillPoints,
        public int $maxSkillPoints,
        public bool $isPrimary,
        public ?int $expansionId = null,
    ) {}
}
