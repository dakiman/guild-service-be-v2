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

        foreach ($data['equipped_items'] ?? [] as $raw) {
            $items[] = new EquippedItem(
                id: (int) ($raw['item']['id'] ?? 0),
                name: (string) ($raw['name'] ?? ''),
                quality: strtolower((string) ($raw['quality']['type'] ?? 'common')),
                slot: strtolower((string) ($raw['slot']['type'] ?? 'unknown')),
                itemLevel: (int) ($raw['level']['value'] ?? 0),
                bonus: $this->mapBonus($raw),
                gems: $this->mapGems($raw),
                enchantments: $this->mapEnchantments($raw),
                setId: $this->mapSetId($raw),
                stats: $this->mapStats($raw),
            );
        }

        return $items;
    }

    /** @return int[] */
    private function mapBonus(array $raw): array
    {
        $bonus = $raw['bonus_list'] ?? [];

        return array_values(array_map('intval', $bonus));
    }

    /**
     * Positional gem list matching Wowhead's `&gems=id1:id2:id3` convention.
     * Empty sockets are represented as 0 so the socket order is preserved.
     *
     * @return int[]
     */
    private function mapGems(array $raw): array
    {
        $sockets = $raw['sockets'] ?? [];
        if ($sockets === []) {
            return [];
        }

        $gems = [];
        foreach ($sockets as $socket) {
            $gems[] = (int) ($socket['item']['id'] ?? 0);
        }

        return $gems;
    }

    /** @return int[] */
    private function mapEnchantments(array $raw): array
    {
        $enchants = [];
        foreach ($raw['enchantments'] ?? [] as $e) {
            if (isset($e['enchantment_id'])) {
                $enchants[] = (int) $e['enchantment_id'];
            }
        }

        return $enchants;
    }

    private function mapSetId(array $raw): ?int
    {
        if (isset($raw['set']['item_set']['id'])) {
            return (int) $raw['set']['item_set']['id'];
        }
        if (isset($raw['set']['id'])) {
            return (int) $raw['set']['id'];
        }

        return null;
    }

    /** @return array<int, array{type: string, value: int, is_negated: bool}> */
    private function mapStats(array $raw): array
    {
        $stats = [];
        foreach ($raw['stats'] ?? [] as $s) {
            $stats[] = [
                'type' => strtolower((string) ($s['type']['type'] ?? '')),
                'value' => (int) ($s['value'] ?? 0),
                'is_negated' => (bool) ($s['is_negated'] ?? false),
            ];
        }

        return $stats;
    }
}
