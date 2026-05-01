<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataRaidEncounter
{
    public function __construct(
        public int $id,
        public int $raidInstanceId,
        public string $name,
        public int $displayOrder,
        public ?int $creatureDisplayId,
        public ?string $portraitUrl,
    ) {}
}
