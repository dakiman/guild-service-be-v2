<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataAchievementCategory;

class GameDataAchievementCategoryMapper
{
    /**
     * Map a single Blizzard /data/wow/achievement-category/{id} response
     * to a GameDataAchievementCategory DTO.
     *
     * Response shape (relevant fields):
     *   { id, name, parent_category: { id, name }?, display_order }
     */
    public function mapDetail(?array $data): ?GameDataAchievementCategory
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return new GameDataAchievementCategory(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Unknown'),
            parentId: isset($data['parent_category']['id'])
                ? (int) $data['parent_category']['id']
                : null,
            displayOrder: isset($data['display_order'])
                ? (int) $data['display_order']
                : 0,
        );
    }

    /**
     * Extract category IDs from a /data/wow/achievement-category/index response.
     *
     * Response shape: { categories: [{ id, name, key: { href } }, ...], root_categories: [...], guild_categories: [...] }
     * We pull from `categories` (root + leaf categories live there).
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['categories'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
