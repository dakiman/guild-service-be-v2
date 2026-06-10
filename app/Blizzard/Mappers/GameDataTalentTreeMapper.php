<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataTalentTree;

class GameDataTalentTreeMapper
{
    /**
     * Map a single Blizzard /data/wow/talent-tree/{treeId}/playable-specialization/{specId}
     * response to a GameDataTalentTree DTO. The mapper flattens the three node families
     * (class, spec, hero) into a topology blob with absolute display_row/display_col
     * positions and a flat global edges list.
     */
    public function mapDetail(?array $data): ?GameDataTalentTree
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $treeId = (int) $data['id'];
        $specId = (int) ($data['playable_specialization']['id'] ?? 0);
        if ($specId === 0) {
            return null;
        }

        $name = (string) ($data['name'] ?? 'Talents');

        $edges = [];

        $classNodes = $this->mapNodes($data['class_talent_nodes'] ?? [], $edges);

        $heroTrees = [];
        $heroNodeIds = [];
        foreach ($data['hero_talent_trees'] ?? [] as $hero) {
            if (! isset($hero['id'])) {
                continue;
            }
            $mapped = $this->mapNodes($hero['hero_talent_nodes'] ?? [], $edges);
            foreach ($mapped as $n) {
                $heroNodeIds[$n['id']] = true;
            }
            $heroTrees[] = [
                'id' => (int) $hero['id'],
                'name' => (string) ($hero['name'] ?? ''),
                'nodes' => $mapped,
            ];
        }

        // Blizzard's `spec_talent_nodes` bundles hero-tree nodes alongside the
        // active spec's nodes. Without filtering, the hero nodes render as a
        // ghost lane in the spec column with no picks. Drop any spec node
        // whose id already appears in a hero tree.
        $specNodesRaw = $this->mapNodes($data['spec_talent_nodes'] ?? [], $edges);
        $specNodes = array_values(array_filter(
            $specNodesRaw,
            fn (array $n) => ! isset($heroNodeIds[$n['id']]),
        ));

        return new GameDataTalentTree(
            treeId: $treeId,
            specId: $specId,
            name: $name,
            tree: [
                'class_nodes' => $classNodes,
                'spec_nodes' => $specNodes,
                'hero_trees' => $heroTrees,
                'edges' => $this->dedupeEdges($edges),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array{from:int,to:int}>  $edges  — accumulator, mutated by reference
     * @return array<int, array<string, mixed>>
     */
    private function mapNodes(array $nodes, array &$edges): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! isset($node['id'])) {
                continue;
            }
            $id = (int) $node['id'];
            $type = strtoupper((string) ($node['node_type']['type'] ?? 'SINGLE')) === 'CHOICE'
                ? 'choice' : 'regular';

            $ranks = [];
            $choiceOptions = null;

            $rawRanks = $node['ranks'] ?? [];
            if ($type === 'choice') {
                $opts = $rawRanks[0]['choice_of_tooltips'] ?? [];
                $choiceOptions = [];
                foreach ($opts as $opt) {
                    $choiceOptions[] = [
                        'talent_id' => (int) ($opt['talent']['id'] ?? 0),
                        'spell_id' => (int) ($opt['spell_tooltip']['spell']['id'] ?? 0),
                        'name' => (string) ($opt['talent']['name'] ?? ''),
                    ];
                }
                if ($choiceOptions === []) {
                    $choiceOptions = null;
                }
            } else {
                foreach ($rawRanks as $rank) {
                    $ranks[] = [
                        'spell_id' => (int) ($rank['tooltip']['spell_tooltip']['spell']['id'] ?? 0),
                        'name' => (string) ($rank['tooltip']['talent']['name'] ?? ''),
                    ];
                }
            }

            foreach ($node['unlocks'] ?? [] as $to) {
                $edges[] = ['from' => $id, 'to' => (int) $to];
            }

            $out[] = [
                'id' => $id,
                'display_row' => (int) ($node['display_row'] ?? 0),
                'display_col' => (int) ($node['display_col'] ?? 0),
                'type' => $type,
                'ranks' => $ranks,
                'choice_options' => $choiceOptions,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{from:int,to:int}>  $edges
     * @return array<int, array{from:int,to:int}>
     */
    private function dedupeEdges(array $edges): array
    {
        $seen = [];
        $out = [];
        foreach ($edges as $e) {
            $key = $e['from'].'-'.$e['to'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $e;
        }

        return $out;
    }
}
