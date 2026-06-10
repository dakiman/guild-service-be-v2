<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterAchievement
{
    public function __construct(
        public int $achievementId,
        public ?int $completedTimestamp,
    ) {}
}
