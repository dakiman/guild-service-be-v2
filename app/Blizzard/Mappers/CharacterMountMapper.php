<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterMount;

class CharacterMountMapper
{
    /**
     * @return CharacterMount[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['mounts'] ?? [] as $entry) {
            $id = (int) ($entry['mount']['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $out[] = new CharacterMount(
                mountId: $id,
                name: (string) ($entry['mount']['name'] ?? 'Unknown'),
                isUseable: (bool) ($entry['is_useable'] ?? false),
            );
        }

        return $out;
    }
}
