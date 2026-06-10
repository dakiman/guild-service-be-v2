<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class PvpBracketStats
{
    public function __construct(
        public string $bracket,
        public int $rating,
        public int $seasonWon,
        public int $seasonLost,
        public int $seasonPlayed,
        public int $weeklyWon,
        public int $weeklyLost,
        public int $weeklyPlayed,
        public ?string $tierName = null,
    ) {}
}
