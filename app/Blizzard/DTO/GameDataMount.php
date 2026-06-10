<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataMount
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public ?string $sourceText,
        public ?int $summonSpellId,
        public ?int $itemId,
    ) {}
}
