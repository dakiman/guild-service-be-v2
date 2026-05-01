<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataRaidInstance
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $expansionId,
        public int $displayOrder,
        public ?string $mediaUrl,
        /** @var int[] encounter IDs the instance exposes (used for the encounter sync fan-out) */
        public array $encounterIds,
    ) {}
}
