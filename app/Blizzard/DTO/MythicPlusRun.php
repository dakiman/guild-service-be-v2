<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class MythicPlusRun
{
    public function __construct(
        public int $season,
        public int $dungeonId,
        public string $dungeonName,
        public int $keystoneLevel,
        public int $duration,
        public int $completedTimestamp,
        public bool $isCompletedOnTime,
        public array $affixes,
        public array $team,
    ) {}
}
