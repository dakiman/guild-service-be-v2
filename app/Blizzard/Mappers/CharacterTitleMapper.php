<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterTitle;

class CharacterTitleMapper
{
    /**
     * @return CharacterTitle[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $activeId = isset($data['active_title']['id']) ? (int) $data['active_title']['id'] : null;
        $activeDisplay = isset($data['active_title']['display_string'])
            ? (string) $data['active_title']['display_string']
            : null;

        $out = [];

        foreach ($data['titles'] ?? [] as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            $displayString = (string) ($entry['display_string'] ?? $name);

            // The active title's display_string from active_title tends to be richer
            // than the per-entry one — prefer it when this row is the active title.
            if ($id === $activeId && $activeDisplay !== null) {
                $displayString = $activeDisplay;
            }

            $out[] = new CharacterTitle(
                titleId: $id,
                name: $name,
                displayString: $displayString,
                isSelected: $id === $activeId,
            );
        }

        return $out;
    }
}
