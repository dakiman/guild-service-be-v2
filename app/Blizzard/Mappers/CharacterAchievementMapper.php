<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterAchievement;

class CharacterAchievementMapper
{
    /**
     * @return CharacterAchievement[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($data['achievements'] ?? [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id === 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $out[] = new CharacterAchievement(
                achievementId: $id,
                completedTimestamp: isset($row['completed_timestamp'])
                    ? (int) $row['completed_timestamp']
                    : null,
            );
        }

        return $out;
    }
}
