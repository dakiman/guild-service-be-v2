<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class RaiderIORun
{
    public function __construct(
        public int $keystoneRunId,
        public int $season,
        public int $dungeonId,
        public string $dungeonName,
        public int $keystoneLevel,
        public int $duration,
        public int $completedTimestamp,
        public bool $isCompletedOnTime,
        public float $score,
        public string $url,
        public array $affixes,
    ) {}
}
