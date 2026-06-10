<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterTitle
{
    public function __construct(
        public int $titleId,
        public string $name,
        public string $displayString,
    ) {}
}
