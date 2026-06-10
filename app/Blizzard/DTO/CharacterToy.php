<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterToy
{
    public function __construct(
        public int $toyId,
        public string $name,
    ) {}
}
