<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataAchievement;

class GameDataAchievementMapper
{
    /**
     * Map a single Blizzard /data/wow/achievement/{id} response to a
     * GameDataAchievement DTO.
     *
     * Response shape (relevant fields):
     *   { id, name, description?, category: { id, name }?, points, is_account_wide?: bool }
     *
     * `is_account_wide` is omitted from older achievements; default to false.
     */
    public function mapDetail(?array $data): ?GameDataAchievement
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return new GameDataAchievement(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Unknown'),
            description: isset($data['description'])
                ? (string) $data['description']
                : null,
            categoryId: isset($data['category']['id'])
                ? (int) $data['category']['id']
                : null,
            points: isset($data['points'])
                ? (int) $data['points']
                : 0,
            isAccountWide: (bool) ($data['is_account_wide'] ?? false),
        );
    }

    /**
     * Extract achievement IDs from a /data/wow/achievement/index response.
     *
     * Response shape: { achievements: [{ id, name, key: { href } }, ...] }
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['achievements'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
