<?php

declare(strict_types=1);

namespace App\Support;

final class SpecRoles
{
    /**
     * Mirrors SPEC_ROLES in frontend/src/utils/wowConstants.ts — copy every
     * entry from there verbatim; that map is the canonical source.
     *
     * @var array<int, string>
     */
    public const ROLES = [
        // Death Knight
        250 => 'tank', 251 => 'dps', 252 => 'dps',
        // Demon Hunter
        577 => 'dps', 581 => 'tank', 1480 => 'dps',
        // Druid
        102 => 'dps', 103 => 'dps', 104 => 'tank', 105 => 'healer',
        // Evoker
        1467 => 'dps', 1468 => 'healer', 1473 => 'dps',
        // Hunter
        253 => 'dps', 254 => 'dps', 255 => 'dps',
        // Mage
        62 => 'dps', 63 => 'dps', 64 => 'dps',
        // Monk
        268 => 'tank', 269 => 'dps', 270 => 'healer',
        // Paladin
        65 => 'healer', 66 => 'tank', 70 => 'dps',
        // Priest
        256 => 'healer', 257 => 'healer', 258 => 'dps',
        // Rogue
        259 => 'dps', 260 => 'dps', 261 => 'dps',
        // Shaman
        262 => 'dps', 263 => 'dps', 264 => 'healer',
        // Warlock
        265 => 'dps', 266 => 'dps', 267 => 'dps',
        // Warrior
        71 => 'dps', 72 => 'dps', 73 => 'tank',
    ];

    public static function roleFor(?int $specId): ?string
    {
        return $specId === null ? null : (self::ROLES[$specId] ?? null);
    }
}
