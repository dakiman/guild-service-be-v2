<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\RaidEncounterKill;
use App\Support\RaidRetention;

class RaidEncounterKillMapper
{
    /**
     * One DTO per (encounter, difficulty): Blizzard lists current raids under
     * both the real expansion and the synthetic "Current Season" grouping, and
     * a duplicate key would abort the slice's single ON CONFLICT upsert batch
     * (SQLSTATE 21000). The real-expansion copy wins so the stats heatmap's
     * expansion_name filter can see the rows.
     *
     * @return RaidEncounterKill[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['expansions'] ?? [] as $exp) {
            $expansionName = (string) ($exp['expansion']['name'] ?? 'Unknown');

            foreach ($exp['instances'] ?? [] as $inst) {
                $instanceId = (int) ($inst['instance']['id'] ?? 0);
                $instanceName = (string) ($inst['instance']['name'] ?? 'Unknown');

                foreach ($inst['modes'] ?? [] as $mode) {
                    $difficulty = strtolower((string) ($mode['difficulty']['type'] ?? ''));

                    foreach ($mode['progress']['encounters'] ?? [] as $enc) {
                        $encId = (int) ($enc['encounter']['id'] ?? 0);

                        if ($encId === 0 || $difficulty === '' || $instanceId === 0) {
                            continue;
                        }

                        $key = $encId.'|'.$difficulty;
                        if (isset($out[$key]) && ($out[$key]->expansionName !== RaidRetention::CURRENT_SEASON || $expansionName === RaidRetention::CURRENT_SEASON)) {
                            continue;
                        }

                        $out[$key] = new RaidEncounterKill(
                            expansionName: $expansionName,
                            instanceId: $instanceId,
                            instanceName: $instanceName,
                            encounterId: $encId,
                            encounterName: (string) ($enc['encounter']['name'] ?? 'Unknown'),
                            difficulty: $difficulty,
                            completedCount: (int) ($enc['completed_count'] ?? 0),
                            lastKillTimestamp: isset($enc['last_kill_timestamp']) ? (int) $enc['last_kill_timestamp'] : null,
                        );
                    }
                }
            }
        }

        return array_values($out);
    }
}
