<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataExpansion
{
    public function __construct(
        public int $id,
        public string $name,
        public int $displayOrder,
    ) {}
}
