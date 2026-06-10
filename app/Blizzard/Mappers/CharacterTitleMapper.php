<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterTitle;

class CharacterTitleMapper
{
    /**
     * @return array{titles: list<CharacterTitle>, activeTitleId: ?int}
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return ['titles' => [], 'activeTitleId' => null];
        }

        $activeId = isset($data['active_title']['id']) ? (int) $data['active_title']['id'] : null;

        $out = [];
        foreach ($data['titles'] ?? [] as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            $displayString = (string) ($entry['display_string'] ?? $name);

            $out[] = new CharacterTitle(
                titleId: $id,
                name: $name,
                displayString: $displayString,
            );
        }

        return ['titles' => $out, 'activeTitleId' => $activeId];
    }
}
