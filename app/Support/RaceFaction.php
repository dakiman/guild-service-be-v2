<?php

declare(strict_types=1);

namespace App\Support;

final class RaceFaction
{
    /**
     * race_id -> 'Alliance' | 'Horde' | null (neutral / unknown).
     *
     * @var array<int, string>
     */
    private const MAP = [
        // Alliance
        1 => 'Alliance',   // Human
        3 => 'Alliance',   // Dwarf
        4 => 'Alliance',   // Night Elf
        7 => 'Alliance',   // Gnome
        11 => 'Alliance',  // Draenei
        22 => 'Alliance',  // Worgen
        25 => 'Alliance',  // Pandaren (Alliance)
        29 => 'Alliance',  // Void Elf
        30 => 'Alliance',  // Lightforged Draenei
        32 => 'Alliance',  // Kul Tiran
        34 => 'Alliance',  // Dark Iron Dwarf
        37 => 'Alliance',  // Mechagnome
        52 => 'Alliance',  // Dracthyr (Alliance)
        85 => 'Alliance',  // Earthen (Alliance)
        86 => 'Alliance',  // Haranir (Alliance)

        // Horde
        2 => 'Horde',      // Orc
        5 => 'Horde',      // Undead
        6 => 'Horde',      // Tauren
        8 => 'Horde',      // Troll
        9 => 'Horde',      // Goblin
        10 => 'Horde',     // Blood Elf
        26 => 'Horde',     // Pandaren (Horde)
        27 => 'Horde',     // Nightborne
        28 => 'Horde',     // Highmountain Tauren
        31 => 'Horde',     // Zandalari Troll
        35 => 'Horde',     // Vulpera
        36 => 'Horde',     // Mag'har Orc
        70 => 'Horde',     // Dracthyr (Horde)
        84 => 'Horde',     // Earthen (Horde)
        91 => 'Horde',     // Haranir (Horde)

        // race_id 24 (neutral Pandaren during char creation) intentionally omitted -> null
    ];

    public static function for(int $raceId): ?string
    {
        return self::MAP[$raceId] ?? null;
    }
}
