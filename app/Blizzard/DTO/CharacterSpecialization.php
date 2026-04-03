<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterSpecialization
{
    public function __construct(
        public string $activeSpecialization,
        public array $classTalents,
        public array $specTalents,
        public array $heroTalents,
    ) {}
}
