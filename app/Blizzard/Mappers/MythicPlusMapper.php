<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\MythicPlusRun;

class MythicPlusMapper
{
    /**
     * @return MythicPlusRun[]
     */
    public function map(array $data, int $season): array
    {
        $runs = [];

        foreach ($data['best_runs'] ?? [] as $run) {
            $affixes = [];
            foreach ($run['keystone_affixes'] ?? [] as $affix) {
                $affixes[] = [
                    'id' => (int) ($affix['id'] ?? 0),
                    'name' => $affix['name'] ?? 'Unknown',
                ];
            }

            $team = [];
            foreach ($run['members'] ?? [] as $member) {
                $team[] = [
                    'name' => $member['character']['name'] ?? 'Unknown',
                    'realm' => $member['character']['realm']['slug'] ?? 'unknown',
                    'realm_name' => $member['character']['realm']['name'] ?? null,
                    'specialization_id' => isset($member['specialization']['id'])
                        ? (int) $member['specialization']['id']
                        : null,
                    'specialization' => $member['specialization']['name'] ?? 'Unknown',
                    'equipped_item_level' => (int) ($member['equipped_item_level'] ?? 0),
                ];
            }

            $runs[] = new MythicPlusRun(
                season: $season,
                dungeonId: (int) ($run['dungeon']['id'] ?? 0),
                dungeonName: $run['dungeon']['name'] ?? 'Unknown',
                keystoneLevel: (int) ($run['keystone_level'] ?? 0),
                duration: (int) ($run['duration'] ?? 0),
                completedTimestamp: (int) ($run['completed_timestamp'] ?? 0),
                isCompletedOnTime: (bool) ($run['is_completed_within_time'] ?? false),
                affixes: $affixes,
                team: $team,
            );
        }

        return $runs;
    }
}
