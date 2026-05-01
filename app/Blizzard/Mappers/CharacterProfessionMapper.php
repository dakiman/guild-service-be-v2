<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterProfession;

class CharacterProfessionMapper
{
    /**
     * Static tier-name-prefix → expansion mapping. Blizzard's
     * /profile/wow/character/{realm}/{name}/professions response identifies
     * each tier by name (e.g. "Khaz Algar Mining", "Dragon Isles Mining"),
     * with Classic-era base tiers having no prefix (just "Mining"). The
     * map is keyed by tier-name prefix and resolved via str_starts_with().
     *
     * Mirrors the GameDataFactionMapper::FACTION_TO_EXPANSION pattern
     * (Plan 4 → Plan 5): expansion mapping lives on the BE so the FE can
     * group/sort by display_order without owning a parallel table.
     *
     * When a new WoW expansion ships, add a row here AND ensure
     * GameDataExpansionSeeder has the corresponding expansion seeded with
     * the next display_order.
     *
     * Expansion IDs match GameDataExpansionSeeder.
     *
     * @var array<string, int> tier-name prefix => expansion_id
     */
    private const TIER_PREFIX_TO_EXPANSION = [
        'Midnight' => 1,          // The War Within / current expansion era
        'Khaz Algar' => 1,        // The War Within
        'Dragon Isles' => 2,      // Dragonflight
        'Shadowlands' => 3,       // Shadowlands
        'Kul Tiran' => 4,         // Battle for Azeroth (Alliance)
        'Zandalari' => 4,         // Battle for Azeroth (Horde)
        'Legion' => 5,            // Legion
        'Draenor' => 6,           // Warlords of Draenor
        'Pandaria' => 7,          // Mists of Pandaria
        'Cataclysm' => 8,         // Cataclysm
        'Northrend' => 9,         // Wrath of the Lich King
        'Outland' => 10,          // The Burning Crusade
        'Classic' => 11,          // Classic / vanilla base tiers (Blizzard prefixes "Classic Mining" etc.)
        // Tiers without a known prefix fall through to null — the FE
        // renders these in a "Legacy" bucket.
    ];

    /**
     * @return CharacterProfession[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['primaries'] ?? [] as $entry) {
            array_push($out, ...$this->mapEntry($entry, true));
        }

        foreach ($data['secondaries'] ?? [] as $entry) {
            array_push($out, ...$this->mapEntry($entry, false));
        }

        return $out;
    }

    /**
     * @return CharacterProfession[]
     */
    private function mapEntry(array $e, bool $isPrimary): array
    {
        $id = (int) ($e['profession']['id'] ?? 0);
        $name = (string) ($e['profession']['name'] ?? 'Unknown');

        if ($id === 0) {
            return [];
        }

        $out = [];
        foreach ($e['tiers'] ?? [] as $t) {
            $tierName = (string) ($t['tier']['name'] ?? 'Unknown');

            $out[] = new CharacterProfession(
                professionId: $id,
                professionName: $name,
                tierName: $tierName,
                skillPoints: (int) ($t['skill_points'] ?? 0),
                maxSkillPoints: (int) ($t['max_skill_points'] ?? 0),
                isPrimary: $isPrimary,
                expansionId: $this->resolveExpansionId($tierName),
            );
        }

        return $out;
    }

    private function resolveExpansionId(string $tierName): ?int
    {
        foreach (self::TIER_PREFIX_TO_EXPANSION as $prefix => $expansionId) {
            if (str_starts_with($tierName, $prefix)) {
                return $expansionId;
            }
        }

        return null;
    }
}
