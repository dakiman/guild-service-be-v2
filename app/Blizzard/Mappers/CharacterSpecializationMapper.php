<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterSpecialization;

class CharacterSpecializationMapper
{
    public function map(array $data): CharacterSpecialization
    {
        $activeSpec = $data['active_specialization']['name'] ?? 'Unknown';
        $loadouts = $data['specializations'] ?? [];

        $classTalents = [];
        $specTalents = [];
        $heroTalents = [];

        foreach ($loadouts as $loadout) {
            if (! isset($loadout['is_active']) || $loadout['is_active'] !== true) {
                continue;
            }

            $classTalents = $this->extractTalents($loadout['selected_class_talents'] ?? []);
            $specTalents = $this->extractTalents($loadout['selected_spec_talents'] ?? []);
            $heroTalents = $this->extractTalents($loadout['selected_hero_talents'] ?? []);

            break;
        }

        return new CharacterSpecialization(
            activeSpecialization: $activeSpec,
            classTalents: $classTalents,
            specTalents: $specTalents,
            heroTalents: $heroTalents,
        );
    }

    private function extractTalents(array $talents): array
    {
        $result = [];

        foreach ($talents as $talent) {
            if (isset($talent['id'])) {
                $result[] = [
                    'id' => (int) $talent['id'],
                    'rank' => (int) ($talent['rank'] ?? 1),
                ];
            }
        }

        return $result;
    }
}
