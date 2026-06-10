<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterMedia
{
    public function __construct(
        public string $avatar,
        public string $inset,
        public string $main,
    ) {}
}
