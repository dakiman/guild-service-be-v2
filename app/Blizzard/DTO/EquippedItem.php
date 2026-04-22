<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class EquippedItem
{
    /**
     * @param  int[]  $bonus          Blizzard `bonus_list` — Wowhead `&bonus=`
     * @param  int[]  $gems           Gem item_ids in socket order — Wowhead `&gems=`
     * @param  int[]  $enchantments   Enchantment ids — Wowhead `&ench=`
     * @param  array<int, array{type: string, value: int, is_negated: bool}>  $stats
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $quality,
        public string $slot,
        public int $itemLevel,
        public array $bonus = [],
        public array $gems = [],
        public array $enchantments = [],
        public ?int $setId = null,
        public array $stats = [],
    ) {}
}
