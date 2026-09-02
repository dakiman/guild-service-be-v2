<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterMythicPlusRating
{
    /**
     * @param  array<int, int>  $perSpec  [specId => rating] — ONLY for this character, not party members
     * @param  ?int  $seasonId  Newest season in the base profile's seasons[] list — the season
     *                          current_mythic_rating belongs to (Blizzard keeps reporting the
     *                          last-played season's rating after a rollover). Null when the
     *                          character has no M+ season history.
     */
    public function __construct(
        public ?int $rating,
        public ?string $color,
        public array $perSpec = [],
        public ?int $seasonId = null,
    ) {}
}
