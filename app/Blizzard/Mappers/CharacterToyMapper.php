<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterToy;

class CharacterToyMapper
{
    /**
     * @return CharacterToy[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['toys'] ?? [] as $entry) {
            $id = (int) ($entry['toy']['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $out[] = new CharacterToy(
                toyId: $id,
                name: (string) ($entry['toy']['name'] ?? 'Unknown'),
            );
        }

        return $out;
    }
}
