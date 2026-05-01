<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataRaidEncounter;

class GameDataRaidEncounterMapper
{
    /**
     * Map a Blizzard /data/wow/journal-encounter/{id} response (plus the
     * companion creature-display media URL) to a GameDataRaidEncounter DTO.
     *
     * Detail response shape (relevant fields):
     *   {
     *     id, name,
     *     instance: { id },
     *     creature_display?: { id },
     *     creature_displays?: [{ id }, ...],
     *     order_index?: int,
     *   }
     *
     * The instance id and order can be missing on some responses — caller
     * supplies fallbacks (the parent instance id and the encounter's index
     * within the instance roster, respectively).
     */
    public function mapDetail(
        ?array $detail,
        ?string $portraitUrl,
        int $fallbackInstanceId,
        int $fallbackOrder,
    ): ?GameDataRaidEncounter {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        return new GameDataRaidEncounter(
            id: (int) $detail['id'],
            raidInstanceId: isset($detail['instance']['id'])
                ? (int) $detail['instance']['id']
                : $fallbackInstanceId,
            name: (string) ($detail['name'] ?? 'Unknown'),
            displayOrder: isset($detail['order_index'])
                ? (int) $detail['order_index']
                : $fallbackOrder,
            creatureDisplayId: $this->extractCreatureDisplayId($detail),
            portraitUrl: $portraitUrl,
        );
    }

    /**
     * Pull the first asset URL out of a
     * /data/wow/media/creature-display/{id} response.
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

    private function extractCreatureDisplayId(array $detail): ?int
    {
        if (isset($detail['creature_display']['id'])) {
            return (int) $detail['creature_display']['id'];
        }

        if (isset($detail['creature_displays'][0]['id'])) {
            return (int) $detail['creature_displays'][0]['id'];
        }

        return null;
    }
}
