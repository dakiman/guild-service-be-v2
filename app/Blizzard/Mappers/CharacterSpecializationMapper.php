<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\DTO\CharacterSpecialization;

class CharacterSpecializationMapper
{
    /**
     * Map the /specializations response to a DTO. When $gameData is supplied we also
     * fetch the talent-tree definition (cached) so each talent carries spell_id and
     * max_rank (count of node.ranks[]). Without $gameData the mapper falls back to
     * the loadout-only fields and max_rank=rank.
     */
    public function map(array $data, ?BlizzardGameDataClient $gameData = null): CharacterSpecialization
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

                $treeId = $this->extractTreeId($loadout['selected_class_talent_tree']['key']['href'] ?? null);
                $nodeMap = ($gameData !== null && $treeId !== null && $activeSpecId !== null)
                    ? $this->buildNodeMap($gameData->getTalentTree($treeId, $activeSpecId))
                    : [];

                $classTalents = $this->extractTalents($loadout['selected_class_talents'] ?? [], $nodeMap);
                $specTalents = $this->extractTalents($loadout['selected_spec_talents'] ?? [], $nodeMap);
                $heroTalents = $this->extractTalents($loadout['selected_hero_talents'] ?? [], $nodeMap);
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

    /**
     * @param  array<int, array<string, mixed>>  $talents
     * @param  array<int, array{max_rank: int, ranks_by_idx: array<int, array{spell_id: int}>}>  $nodeMap
     * @return array<int, array{id: int, spell_id: int, rank: int, max_rank: int}>
     */
    private function extractTalents(array $talents, array $nodeMap): array
    {
        $result = [];

        foreach ($talents as $talent) {
            if (! isset($talent['id'])) {
                continue;
            }

            $nodeId = (int) $talent['id'];
            $rank = (int) ($talent['rank'] ?? 1);
            $node = $nodeMap[$nodeId] ?? null;

            // spell_id priority: loadout tooltip > tree-rank lookup > 0 (no Wowhead link)
            $spellId = (int) ($talent['tooltip']['spell_tooltip']['spell']['id']
                ?? $node['ranks_by_idx'][$rank - 1]['spell_id']
                ?? 0);

            $maxRank = $node['max_rank'] ?? $rank;

            $result[] = [
                'id' => $nodeId,
                'spell_id' => $spellId,
                'rank' => $rank,
                'max_rank' => $maxRank,
            ];
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

    /**
     * Parse a talent-tree id from Blizzard's href like:
     *   https://eu.api.blizzard.com/data/wow/talent-tree/795?namespace=...
     * Returns null when the href is missing or unparseable.
     */
    private function extractTreeId(?string $href): ?int
    {
        if ($href === null || $href === '') {
            return null;
        }

        return preg_match('#/talent-tree/(\d+)#', $href, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Flatten the talent-tree response into a single nodeId-keyed lookup of
     * {max_rank, ranks_by_idx} where ranks_by_idx[rank-1] gives the spell_id for
     * that rank. Covers class_talent_nodes, spec_talent_nodes, and every subtree
     * inside hero_talent_trees.
     *
     * @return array<int, array{max_rank: int, ranks_by_idx: array<int, array{spell_id: int}>}>
     */
    private function buildNodeMap(?array $tree): array
    {
        if ($tree === null) {
            return [];
        }

        $map = [];

        $eat = function (array $nodes) use (&$map): void {
            foreach ($nodes as $node) {
                if (! isset($node['id'])) {
                    continue;
                }
                $ranks = $node['ranks'] ?? [];
                $ranksByIdx = [];
                foreach ($ranks as $i => $rank) {
                    $ranksByIdx[$i] = [
                        'spell_id' => (int) ($rank['tooltip']['spell_tooltip']['spell']['id'] ?? 0),
                    ];
                }
                $map[(int) $node['id']] = [
                    'max_rank' => count($ranks),
                    'ranks_by_idx' => $ranksByIdx,
                ];
            }
        };

        $eat($tree['class_talent_nodes'] ?? []);
        $eat($tree['spec_talent_nodes'] ?? []);
        foreach ($tree['hero_talent_trees'] ?? [] as $heroTree) {
            $eat($heroTree['hero_talent_nodes'] ?? []);
        }

        return $map;
    }
}
