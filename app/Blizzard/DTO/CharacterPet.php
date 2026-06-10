<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterPet
{
    public function __construct(
        public int $petId,
        public int $speciesId,
        public string $name,
        public int $level,
        public ?int $breedId,
        public ?string $quality,
        public bool $isFavorite,
        public ?int $creatureDisplayId,
    ) {}
}
