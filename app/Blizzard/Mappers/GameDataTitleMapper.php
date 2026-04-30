<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataTitle;

class GameDataTitleMapper
{
    /**
     * Map a single Blizzard /data/wow/title/{id} response to a GameDataTitle DTO.
     *
     * Blizzard's title detail endpoint exposes:
     *   { id, name, gender_name: { male, female } }
     *
     * Most titles ship gender-neutral copy in both gender_name slots
     * (e.g. "the Hallowed" reads identically). Some titles do diverge —
     * "Lord {name}" vs "Lady {name}" — and those are the load-bearing case
     * for this slice.
     *
     * Some legacy or partial responses omit `gender_name` entirely; in
     * that case we fall back to `name` for both columns so the FE always
     * has something to render and downstream code does not need to handle
     * empty strings.
     */
    public function mapDetail(?array $data): ?GameDataTitle
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $fallback = (string) ($data['name'] ?? '');

        $male = isset($data['gender_name']['male'])
            ? (string) $data['gender_name']['male']
            : $fallback;

        $female = isset($data['gender_name']['female'])
            ? (string) $data['gender_name']['female']
            : $fallback;

        return new GameDataTitle(
            id: (int) $data['id'],
            nameMale: $male,
            nameFemale: $female,
        );
    }

    /**
     * Extract title IDs from a /data/wow/title/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['titles'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
