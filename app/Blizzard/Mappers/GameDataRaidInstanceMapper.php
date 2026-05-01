<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataRaidInstance;

class GameDataRaidInstanceMapper
{
    /**
     * Translation table from Blizzard's journal-expansion ID (returned in
     * /data/wow/journal-instance/{id}.expansion.id) to our `game_data_expansions.id`
     * seeded by GameDataExpansionSeeder. Mirrors the Plan-5 FACTION_TO_EXPANSION
     * precedent — Blizzard's ID space is quirky and not aligned with ours.
     *
     * Source of truth: GET /data/wow/journal-expansion/index (run once per patch
     * to harvest new IDs). Unmapped IDs (e.g. 505 "Current Season", 516 "Midnight")
     * fall through to null and the FK on `game_data_raid_instances.expansion_id`
     * accepts the null.
     */
    private const BLIZZARD_JOURNAL_EXPANSION_TO_OUR_ID = [
        516 => 12, // Midnight (current as of patch 12.x; display_order=1)
        514 => 1,  // The War Within
        503 => 2,  // Dragonflight
        499 => 3,  // Shadowlands
        396 => 4,  // Battle for Azeroth
        395 => 5,  // Legion
        124 => 6,  // Warlords of Draenor
        74 => 7,   // Mists of Pandaria
        73 => 8,   // Cataclysm
        72 => 9,   // Wrath of the Lich King
        70 => 10,  // Burning Crusade
        68 => 11,  // Classic
    ];

    /**
     * Map a Blizzard /data/wow/journal-instance/{id} response (plus the
     * companion media response) to a GameDataRaidInstance DTO.
     *
     * Detail response shape (relevant fields):
     *   { id, name, expansion: { id }, order_index, encounters: [{ id, name }, ...] }
     *
     * Media response shape:
     *   { assets: [{ key: "tile" | "...", value: "<url>" }, ...] }
     * The first asset's `value` is the raid background image; we take the
     * first assets entry unconditionally because Blizzard typically only
     * emits one for journal-instance media.
     */
    public function mapDetail(?array $detail, ?string $mediaUrl): ?GameDataRaidInstance
    {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        // Blizzard's /data/wow/journal-instance/index returns RAIDs *and* DUNGEONs
        // under the same endpoint. We only persist RAIDs here; dungeons are
        // handled by GameDataMythicKeystoneDungeonMapper from the
        // /data/wow/mythic-keystone/dungeon endpoints.
        if (($detail['category']['type'] ?? null) !== 'RAID') {
            return null;
        }

        // Blizzard also exposes a per-expansion meta entry named the same as
        // the expansion itself (e.g. an instance literally named "Midnight"
        // under the Midnight expansion) which bundles outdoor / world-boss
        // encounters but isn't a real raid players can queue. Skip these so
        // only actual raids land in the table.
        $name = (string) ($detail['name'] ?? 'Unknown');
        $expansionName = isset($detail['expansion']['name'])
            ? (string) $detail['expansion']['name']
            : null;
        if ($expansionName !== null && $name === $expansionName) {
            return null;
        }

        $encounterIds = [];
        foreach ($detail['encounters'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $encounterIds[] = (int) $entry['id'];
            }
        }

        $blizzardExpansionId = isset($detail['expansion']['id'])
            ? (int) $detail['expansion']['id']
            : null;

        return new GameDataRaidInstance(
            id: (int) $detail['id'],
            name: $name,
            expansionId: $blizzardExpansionId !== null
                ? (self::BLIZZARD_JOURNAL_EXPANSION_TO_OUR_ID[$blizzardExpansionId] ?? null)
                : null,
            displayOrder: isset($detail['order_index'])
                ? (int) $detail['order_index']
                : 0,
            mediaUrl: $mediaUrl,
            encounterIds: $encounterIds,
        );
    }

    /**
     * Extract instance IDs from a /data/wow/journal-instance/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['instances'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }

    /**
     * Pull the first asset URL out of a /data/wow/media/journal-instance/{id}
     * response. Returns null if no assets or input is null.
     */
    public function extractMediaUrl(?array $media): ?string
    {
        if ($media === null) {
            return null;
        }

        foreach ($media['assets'] ?? [] as $asset) {
            if (isset($asset['value']) && is_string($asset['value']) && $asset['value'] !== '') {
                return $asset['value'];
            }
        }

        return null;
    }
}
