<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterProfession;

class CharacterProfessionMapper
{
    /**
     * @return CharacterProfession[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['primaries'] ?? [] as $entry) {
            array_push($out, ...$this->mapEntry($entry, true));
        }

        foreach ($data['secondaries'] ?? [] as $entry) {
            array_push($out, ...$this->mapEntry($entry, false));
        }

        return $out;
    }

    /**
     * @return CharacterProfession[]
     */
    private function mapEntry(array $e, bool $isPrimary): array
    {
        $id = (int) ($e['profession']['id'] ?? 0);
        $name = (string) ($e['profession']['name'] ?? 'Unknown');

        if ($id === 0) {
            return [];
        }

        $out = [];
        foreach ($e['tiers'] ?? [] as $t) {
            $out[] = new CharacterProfession(
                professionId: $id,
                professionName: $name,
                tierName: (string) ($t['tier']['name'] ?? 'Unknown'),
                skillPoints: (int) ($t['skill_points'] ?? 0),
                maxSkillPoints: (int) ($t['max_skill_points'] ?? 0),
                isPrimary: $isPrimary,
            );
        }

        return $out;
    }
}
