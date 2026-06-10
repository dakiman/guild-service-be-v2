<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterReputation;

class CharacterReputationMapper
{
    /**
     * @return CharacterReputation[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['reputations'] ?? [] as $entry) {
            $factionId = (int) ($entry['faction']['id'] ?? 0);
            if ($factionId === 0) {
                continue;
            }

            $out[] = new CharacterReputation(
                factionId: $factionId,
                factionName: (string) ($entry['faction']['name'] ?? 'Unknown'),
                standing: strtolower((string) ($entry['standing']['name'] ?? 'neutral')),
                value: (int) ($entry['standing']['raw'] ?? 0),
                max: (int) ($entry['standing']['max'] ?? 0),
            );
        }

        return $out;
    }
}
