<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterStats;

class CharacterStatsMapper
{
    /**
     * Strip Blizzard's `_links` and `character` envelope keys; keep the rest
     * of the payload verbatim. The FE picks which fields it cares about.
     *
     * @param  array<string, mixed>  $data
     */
    public function map(array $data): CharacterStats
    {
        $fields = $data;
        unset($fields['_links'], $fields['character']);

        return new CharacterStats(
            fields: $fields,
            health: isset($fields['health']) ? (int) $fields['health'] : null,
            power: isset($fields['power']) ? (int) $fields['power'] : null,
            powerType: isset($fields['power_type']['name'])
                ? (string) $fields['power_type']['name']
                : null,
            strength: $this->primaryStat($fields, 'strength'),
            agility: $this->primaryStat($fields, 'agility'),
            intellect: $this->primaryStat($fields, 'intellect'),
            stamina: $this->primaryStat($fields, 'stamina'),
        );
    }

    /**
     * Blizzard nests primary stats as { base, effective }.
     *
     * @param  array<string, mixed>  $fields
     */
    private function primaryStat(array $fields, string $key): ?int
    {
        if (! isset($fields[$key])) {
            return null;
        }

        $entry = $fields[$key];
        if (is_array($entry) && isset($entry['effective'])) {
            return (int) $entry['effective'];
        }
        if (is_numeric($entry)) {
            return (int) $entry;
        }

        return null;
    }
}
