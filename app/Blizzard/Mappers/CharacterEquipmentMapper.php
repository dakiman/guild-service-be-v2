<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\EquippedItem;

class CharacterEquipmentMapper
{
    /**
     * @return EquippedItem[]
     */
    public function map(array $data): array
    {
        $items = [];

        foreach ($data['equipped_items'] ?? [] as $item) {
            $items[] = new EquippedItem(
                id: (int) ($item['item']['id'] ?? 0),
                itemLevel: (int) ($item['level']['value'] ?? 0),
                quality: strtolower($item['quality']['type'] ?? 'common'),
                slot: strtolower($item['slot']['type'] ?? 'unknown'),
            );
        }

        return $items;
    }
}
