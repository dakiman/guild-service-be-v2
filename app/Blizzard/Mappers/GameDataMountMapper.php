<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataMount;

class GameDataMountMapper
{
    /**
     * Map a single Blizzard /data/wow/mount/{id} response to a GameDataMount DTO.
     *
     * Notable extractions:
     *  - `description` arrives as a plain string when locale is set in the
     *    request, but some legacy responses nest it as `description.en_GB`.
     *    Both shapes are tolerated.
     *  - `source` is an object `{ type: "DROP", name: "..." }` describing how
     *    the mount is acquired; we flatten to a single `source_text` like
     *    "Drop: Onyxia" using title-cased type + ": " + name.
     *  - `summon_spell` is the spell that summons the mount; its `id` is what
     *    powers Wowhead's `spell=` tooltip widget on the FE.
     *  - `item.id` (when present) is the in-game item that teaches the mount;
     *    useful for "Source: this item" rendering.
     */
    public function mapDetail(?array $data): ?GameDataMount
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return new GameDataMount(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Unknown'),
            description: $this->extractDescription($data),
            sourceText: $this->extractSourceText($data),
            summonSpellId: isset($data['summon_spell']['id'])
                ? (int) $data['summon_spell']['id']
                : null,
            itemId: isset($data['item']['id'])
                ? (int) $data['item']['id']
                : null,
        );
    }

    /**
     * Description may arrive as a plain string (typical when ?locale=en_GB
     * is set on the request), or — defensively — as a nested locale map.
     */
    private function extractDescription(array $data): ?string
    {
        if (! isset($data['description'])) {
            return null;
        }

        $d = $data['description'];

        if (is_string($d)) {
            return $d !== '' ? $d : null;
        }

        if (is_array($d) && isset($d['en_GB']) && is_string($d['en_GB'])) {
            return $d['en_GB'] !== '' ? $d['en_GB'] : null;
        }

        return null;
    }

    /**
     * Flatten { type: "DROP", name: "Onyxia" } to "Drop: Onyxia".
     * Returns null if either field is missing.
     */
    private function extractSourceText(array $data): ?string
    {
        $type = $data['source']['type'] ?? null;
        $name = $data['source']['name'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        if (! is_string($name) || $name === '') {
            return ucfirst(strtolower($type));
        }

        return ucfirst(strtolower($type)).': '.$name;
    }

    /**
     * Extract mount IDs from a /data/wow/mount/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['mounts'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
