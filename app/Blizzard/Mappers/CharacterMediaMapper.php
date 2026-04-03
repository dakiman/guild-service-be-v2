<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterMedia;

class CharacterMediaMapper
{
    public function map(array $data): CharacterMedia
    {
        $assets = $this->indexAssets($data['assets'] ?? []);

        return new CharacterMedia(
            avatar: $assets['avatar'] ?? '',
            inset: $assets['inset'] ?? '',
            main: $assets['main-raw'] ?? $assets['main'] ?? '',
        );
    }

    private function indexAssets(array $assets): array
    {
        $indexed = [];

        foreach ($assets as $asset) {
            if (isset($asset['key'], $asset['value'])) {
                $indexed[$asset['key']] = $asset['value'];
            }
        }

        return $indexed;
    }
}
