<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataMythicKeystoneDungeon;

class GameDataMythicKeystoneDungeonMapper
{
    /**
     * Map a Blizzard /data/wow/mythic-keystone/dungeon/{id} response (plus
     * the companion media URL and a journal-instance id resolved by the
     * caller from the season payload) to a GameDataMythicKeystoneDungeon DTO.
     *
     * Detail response shape (relevant fields):
     *   { id, name, map: { id }, keystone_upgrades: [...] }
     *
     * Note: Blizzard's mythic-keystone dungeon detail does NOT directly
     * expose `journal_instance` — the FE-side fallback portrait join uses
     * a value supplied by the sync command (typically resolved from the
     * season's `dungeons[].id` lookup table or hand-mapped per patch).
     */
    public function mapDetail(
        ?array $detail,
        ?string $mediaUrl,
        ?int $journalInstanceId,
    ): ?GameDataMythicKeystoneDungeon {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        return new GameDataMythicKeystoneDungeon(
            id: (int) $detail['id'],
            name: (string) ($detail['name'] ?? 'Unknown'),
            mediaUrl: $mediaUrl,
            journalInstanceId: $journalInstanceId,
            keystoneUpgrades: $this->extractKeystoneUpgrades(
                is_array($detail['keystone_upgrades'] ?? null) ? $detail['keystone_upgrades'] : null,
            ),
        );
    }

    /**
     * Extract dungeon IDs from a /data/wow/mythic-keystone/dungeon/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['dungeons'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }

    /**
     * Pull the first asset URL from a /data/wow/media/...{dungeon-icon} response.
     * Note: Blizzard does not currently emit a media doc for keystone dungeons
     * — this method exists for symmetry with the raid/affix mappers and may
     * be wired in if/when Blizzard extends the API.
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

    /**
     * Normalize Blizzard's keystone_upgrades (par times per chest level:
     * beat qualifying_duration ms → that many chests). Null when absent or
     * unusable so the DB column stays NULL rather than '[]'.
     *
     * @return list<array{upgrade_level: int, qualifying_duration: int}>|null
     */
    private function extractKeystoneUpgrades(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach ($raw as $entry) {
            if (isset($entry['upgrade_level'], $entry['qualifying_duration'])) {
                $out[] = [
                    'upgrade_level' => (int) $entry['upgrade_level'],
                    'qualifying_duration' => (int) $entry['qualifying_duration'],
                ];
            }
        }

        if ($out === []) {
            return null;
        }

        usort($out, fn (array $a, array $b) => $a['upgrade_level'] <=> $b['upgrade_level']);

        return $out;
    }
}
