<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterSpecialization;

class CharacterSpecializationMapper
{
    public function map(array $data): CharacterSpecialization
    {
        $activeSpec = (string) ($data['active_specialization']['name'] ?? 'Unknown');
        $activeSpecId = isset($data['active_specialization']['id'])
            ? (int) $data['active_specialization']['id']
            : null;
        $specs = $data['specializations'] ?? [];

        $classTalents = [];
        $specTalents = [];
        $heroTalents = [];
        $pvpTalents = [];
        $loadoutCode = null;

        foreach ($specs as $spec) {
            $specId = isset($spec['specialization']['id']) ? (int) $spec['specialization']['id'] : null;
            if ($activeSpecId !== null && $specId !== $activeSpecId) {
                continue;
            }

            $pvpTalents = $this->extractPvpTalents($spec['pvp_talent_slots'] ?? []);

            foreach ($spec['loadouts'] ?? [] as $loadout) {
                if (! isset($loadout['is_active']) || $loadout['is_active'] !== true) {
                    continue;
                }

                $classTalents = $this->extractTalents($loadout['selected_class_talents'] ?? []);
                $specTalents = $this->extractTalents($loadout['selected_spec_talents'] ?? []);
                $heroTalents = $this->extractTalents($loadout['selected_hero_talents'] ?? []);
                $loadoutCode = isset($loadout['talent_loadout_code'])
                    ? (string) $loadout['talent_loadout_code']
                    : null;

                break;
            }

            break;
        }

        return new CharacterSpecialization(
            activeSpecialization: $activeSpec,
            classTalents: $classTalents,
            specTalents: $specTalents,
            heroTalents: $heroTalents,
            pvpTalents: $pvpTalents,
            talentLoadoutCode: $loadoutCode,
        );
    }

    /** @return array<int, array{id: int, rank: int}> */
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

    /** @return array<int, array{slot: int, talent_id: int, spell_id: int}> */
    private function extractPvpTalents(array $slots): array
    {
        $result = [];

        foreach ($slots as $slot) {
            $result[] = [
                'slot' => (int) ($slot['slot_number'] ?? 0),
                'talent_id' => (int) ($slot['selected']['talent']['id'] ?? 0),
                'spell_id' => (int) ($slot['selected']['spell_tooltip']['spell']['id'] ?? 0),
            ];
        }

        return $result;
    }
}
