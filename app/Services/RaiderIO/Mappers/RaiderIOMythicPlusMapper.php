<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Mappers;

use App\Services\RaiderIO\DTO\RaiderIORun;
use Carbon\Carbon;

class RaiderIOMythicPlusMapper
{
    /**
     * @return RaiderIORun[]
     */
    public function mapCharacterProfileRuns(array $profileData, int $season): array
    {
        $seen = [];
        $runs = [];

        foreach (['mythic_plus_recent_runs', 'mythic_plus_best_runs', 'mythic_plus_highest_level_runs'] as $field) {
            foreach ($profileData[$field] ?? [] as $run) {
                $keystoneRunId = $this->extractKeystoneRunId($run['url'] ?? '');
                if ($keystoneRunId === null || isset($seen[$keystoneRunId])) {
                    continue;
                }
                $seen[$keystoneRunId] = true;

                $runs[] = new RaiderIORun(
                    keystoneRunId: $keystoneRunId,
                    season: $season,
                    dungeonId: (int) ($run['map_challenge_mode_id'] ?? 0),
                    dungeonName: $run['dungeon'] ?? 'Unknown',
                    keystoneLevel: (int) ($run['mythic_level'] ?? 0),
                    duration: (int) ($run['clear_time_ms'] ?? 0),
                    completedTimestamp: Carbon::parse($run['completed_at'])->getTimestampMs(),
                    isCompletedOnTime: ($run['num_keystone_upgrades'] ?? 0) > 0,
                    score: (float) ($run['score'] ?? 0),
                    url: $run['url'] ?? '',
                    affixes: array_map(
                        fn (array $a) => ['id' => (int) ($a['id'] ?? 0), 'name' => $a['name'] ?? 'Unknown'],
                        $run['affixes'] ?? [],
                    ),
                );
            }
        }

        return $runs;
    }

    /**
     * @return array<int, array{name: string, realm: string, realm_name: ?string, specialization_id: ?int, specialization: ?string, equipped_item_level: ?int}>
     */
    public function mapRunDetailsRoster(array $detailsData): array
    {
        $team = [];

        foreach ($detailsData['roster'] ?? [] as $entry) {
            $character = $entry['character'] ?? [];
            $team[] = [
                'name' => $character['name'] ?? 'Unknown',
                'realm' => $character['realm']['slug'] ?? 'unknown',
                'realm_name' => $character['realm']['name'] ?? null,
                'specialization_id' => isset($character['spec']['id']) ? (int) $character['spec']['id'] : null,
                'specialization' => $character['spec']['name'] ?? null,
                'equipped_item_level' => isset($entry['items']['item_level_equipped'])
                    ? (int) $entry['items']['item_level_equipped']
                    : null,
            ];
        }

        return $team;
    }

    private function extractKeystoneRunId(string $url): ?int
    {
        if (preg_match('/mythic-plus-runs\/[^\/]+\/(\d+)/', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
