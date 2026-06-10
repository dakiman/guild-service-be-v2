<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\RaidEncounterKill;

class RaidEncounterKillMapper
{
    /**
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

                        $out[] = new RaidEncounterKill(
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

        return $out;
    }
}
