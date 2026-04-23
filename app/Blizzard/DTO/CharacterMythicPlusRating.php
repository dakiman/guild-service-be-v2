<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterMythicPlusRating
{
    /**
     * @param  array<int, int>  $perSpec  [specId => rating] — ONLY for this character, not party members
     */
    public function __construct(
        public ?int $rating,
        public ?string $color,
        public array $perSpec = [],
    ) {}
}
