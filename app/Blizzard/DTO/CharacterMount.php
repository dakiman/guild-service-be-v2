<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterMount
{
    public function __construct(
        public int $mountId,
        public string $name,
        public bool $isUseable,
    ) {}
}
