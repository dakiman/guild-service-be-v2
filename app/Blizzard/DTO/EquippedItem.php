<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class EquippedItem
{
    public function __construct(
        public int $id,
        public int $itemLevel,
        public string $quality,
        public string $slot,
    ) {}
}
