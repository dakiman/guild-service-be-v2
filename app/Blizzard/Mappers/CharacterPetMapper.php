<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterPet;

class CharacterPetMapper
{
    /**
     * @return CharacterPet[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['pets'] ?? [] as $entry) {
            $petId = (int) ($entry['id'] ?? 0);
            if ($petId === 0) {
                continue;
            }

            $quality = isset($entry['quality']['type'])
                ? strtolower((string) $entry['quality']['type'])
                : null;

            $breedId = isset($entry['stats']['breed_id'])
                ? (int) $entry['stats']['breed_id']
                : null;

            $creatureDisplayId = isset($entry['species']['creature_display']['id'])
                ? (int) $entry['species']['creature_display']['id']
                : null;

            $out[] = new CharacterPet(
                petId: $petId,
                speciesId: (int) ($entry['species']['id'] ?? 0),
                name: (string) ($entry['name'] ?? $entry['species']['name'] ?? 'Unknown'),
                level: (int) ($entry['level'] ?? 1),
                breedId: $breedId,
                quality: $quality,
                isFavorite: (bool) ($entry['is_favorite'] ?? false),
                creatureDisplayId: $creatureDisplayId,
            );
        }

        return $out;
    }
}
