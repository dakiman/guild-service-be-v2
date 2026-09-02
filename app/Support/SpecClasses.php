<?php

declare(strict_types=1);

namespace App\Support;

final class SpecClasses
{
    /**
     * Mirrors SPEC_TO_CLASS in frontend/src/utils/wowConstants.ts — copy every
     * entry from there verbatim; that map is the canonical source.
     *
     * @var array<int, int>
     */
    public const CLASSES = [
        // Warrior
        71 => 1, 72 => 1, 73 => 1,
        // Paladin
        65 => 2, 66 => 2, 70 => 2,
        // Hunter
        253 => 3, 254 => 3, 255 => 3,
        // Rogue
        259 => 4, 260 => 4, 261 => 4,
        // Priest
        256 => 5, 257 => 5, 258 => 5,
        // Death Knight
        250 => 6, 251 => 6, 252 => 6,
        // Shaman
        262 => 7, 263 => 7, 264 => 7,
        // Mage
        62 => 8, 63 => 8, 64 => 8,
        // Warlock
        265 => 9, 266 => 9, 267 => 9,
        // Monk
        268 => 10, 269 => 10, 270 => 10,
        // Druid
        102 => 11, 103 => 11, 104 => 11, 105 => 11,
        // Demon Hunter
        577 => 12, 581 => 12, 1480 => 12,
        // Evoker
        1467 => 13, 1468 => 13, 1473 => 13,
    ];

    public static function classFor(?int $specId): ?int
    {
        return $specId === null ? null : (self::CLASSES[$specId] ?? null);
    }
}
