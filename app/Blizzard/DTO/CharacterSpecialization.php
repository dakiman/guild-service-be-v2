<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterSpecialization
{
    /**
     * @param  array<int, array{id: int, rank: int}>  $classTalents
     * @param  array<int, array{id: int, rank: int}>  $specTalents
     * @param  array<int, array{id: int, rank: int}>  $heroTalents
     * @param  array<int, array{slot: int, talent_id: int, spell_id: int}>  $pvpTalents
     */
    public function __construct(
        public string $activeSpecialization,
        public array $classTalents = [],
        public array $specTalents = [],
        public array $heroTalents = [],
        public array $pvpTalents = [],
        public ?string $talentLoadoutCode = null,
    ) {}
}
