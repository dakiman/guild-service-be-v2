<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataRaidInstance;

class GameDataRaidInstanceMapper
{
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

        $encounterIds = [];
        foreach ($detail['encounters'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $encounterIds[] = (int) $entry['id'];
            }
        }

        return new GameDataRaidInstance(
            id: (int) $detail['id'],
            name: (string) ($detail['name'] ?? 'Unknown'),
            expansionId: isset($detail['expansion']['id'])
                ? (int) $detail['expansion']['id']
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
