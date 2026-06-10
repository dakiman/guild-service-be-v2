<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataKeystoneAffix;

class GameDataKeystoneAffixMapper
{
    /**
     * Map a Blizzard /data/wow/keystone-affix/{id} response (plus the
     * companion icon URL from /data/wow/media/keystone-affix/{id}) to a
     * GameDataKeystoneAffix DTO.
     */
    public function mapDetail(?array $detail, ?string $iconUrl): ?GameDataKeystoneAffix
    {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        return new GameDataKeystoneAffix(
            id: (int) $detail['id'],
            name: (string) ($detail['name'] ?? 'Unknown'),
            iconUrl: $iconUrl,
        );
    }

    /**
     * Extract affix IDs from a /data/wow/keystone-affix/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['affixes'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }

    public function extractIconUrl(?array $media): ?string
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
