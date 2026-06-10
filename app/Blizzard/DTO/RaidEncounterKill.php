<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class RaidEncounterKill
{
    public function __construct(
        public string $expansionName,
        public int $instanceId,
        public string $instanceName,
        public int $encounterId,
        public string $encounterName,
        public string $difficulty,
        public int $completedCount,
        public ?int $lastKillTimestamp,
    ) {}
}
