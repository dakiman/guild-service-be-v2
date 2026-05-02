<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataTalentTree
{
    /**
     * @param  array{
     *     class_nodes: array<int, array<string, mixed>>,
     *     spec_nodes: array<int, array<string, mixed>>,
     *     hero_trees: array<int, array{id: int, name: string, nodes: array<int, array<string, mixed>>}>,
     *     edges: array<int, array{from: int, to: int}>,
     *   }  $tree
     */
    public function __construct(
        public int $treeId,
        public int $specId,
        public string $name,
        public array $tree,
    ) {}
}
