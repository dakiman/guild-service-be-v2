<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataFaction;

class GameDataFactionMapper
{
    /**
     * Static faction → expansion mapping. Blizzard's
     * /data/wow/reputation-faction/{id} response does not expose expansion,
     * so this map is maintained in-tree and extended each patch.
     *
     * Migrated from the FE's EXPANSION_BY_FACTION_ID map at
     * frontend/src/components/character/ReputationsList.vue (Plan 4 → Plan 5).
     *
     * @var array<int, int> faction_id => expansion_id (matches GameDataExpansionSeeder ids)
     */
    private const FACTION_TO_EXPANSION = [
        // The War Within (expansion_id 1)
        2570 => 1, // Council of Dornogal
        2574 => 1, // The Assembly of the Deeps
        2590 => 1, // Hallowfall Arathi
        2600 => 1, // The Severed Threads
        // Dragonflight (expansion_id 2)
        2510 => 2, // Valdrakken Accord
        2511 => 2, // Iskaara Tuskarr
        2503 => 2, // Maruuk Centaur
        2507 => 2, // Dragonscale Expedition
        2564 => 2, // Loamm Niffen
        2553 => 2, // Soridormi
        2544 => 2, // Artisan's Consortium
    ];

    /**
     * Map a single Blizzard /data/wow/reputation-faction/{id} response
     * to a GameDataFaction DTO.
     */
    public function mapDetail(?array $data): ?GameDataFaction
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $id = (int) $data['id'];

        return new GameDataFaction(
            id: $id,
            name: (string) ($data['name'] ?? 'Unknown'),
            parentFactionId: isset($data['category']['id']) ? (int) $data['category']['id'] : null,
            expansionId: self::FACTION_TO_EXPANSION[$id] ?? null,
        );
    }

    /**
     * Extract faction IDs from a /data/wow/reputation-faction/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['factions'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
