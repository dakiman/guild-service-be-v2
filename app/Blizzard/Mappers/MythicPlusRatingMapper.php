<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterMythicPlusRating;
use App\Support\BlizzardIdentity;

class MythicPlusRatingMapper
{
    /**
     * @param  string  $characterName  to match against members[].character.name (case-insensitive)
     * @param  string  $characterRealm  realm slug (e.g., "the-maelstrom") to match against members[].character.realm.slug
     */
    public function map(
        ?array $base,
        ?array $season,
        string $characterName,
        string $characterRealm,
    ): CharacterMythicPlusRating {
        $rating = null;
        $color = null;

        if (isset($base['current_mythic_rating']['rating'])) {
            $rating = (int) round((float) $base['current_mythic_rating']['rating']);
            $color = $this->rgbToHex($base['current_mythic_rating']['color'] ?? null);
        }

        $seasonIds = [];
        foreach ((array) ($base['seasons'] ?? []) as $seasonEntry) {
            if (is_array($seasonEntry) && isset($seasonEntry['id'])) {
                $seasonIds[] = (int) $seasonEntry['id'];
            }
        }

        return new CharacterMythicPlusRating(
            rating: $rating,
            color: $color,
            perSpec: $this->aggregatePerSpec($season['best_runs'] ?? [], $characterName, $characterRealm),
            seasonId: $seasonIds === [] ? null : max($seasonIds),
        );
    }

    private function rgbToHex(?array $c): ?string
    {
        if (! is_array($c) || ! isset($c['r'], $c['g'], $c['b'])) {
            return null;
        }

        return sprintf('#%02x%02x%02x', (int) $c['r'], (int) $c['g'], (int) $c['b']);
    }

    /**
     * For each run, find the member matching the synced character's identity
     * and credit that single member's spec_id with the run's rating.
     *
     * @return array<int, int> specId => highest single-run rating for THIS character
     */
    private function aggregatePerSpec(array $bestRuns, string $name, string $realm): array
    {
        $wantName = BlizzardIdentity::name($name);
        $wantRealm = strtolower($realm); // realm slugs are ASCII
        $perSpec = [];

        foreach ($bestRuns as $run) {
            $r = (int) round((float) ($run['mythic_rating']['rating'] ?? 0.0));
            if ($r === 0) {
                continue;
            }

            foreach ($run['members'] ?? [] as $m) {
                $memberName = BlizzardIdentity::name((string) ($m['character']['name'] ?? ''));
                $memberRealm = strtolower((string) ($m['character']['realm']['slug'] ?? '')); // realm slugs are ASCII
                if ($memberName !== $wantName || $memberRealm !== $wantRealm) {
                    continue;
                }

                $id = (int) ($m['specialization']['id'] ?? 0);
                if ($id === 0) {
                    break; // found the character, no more iteration on this run
                }
                if (! isset($perSpec[$id]) || $r > $perSpec[$id]) {
                    $perSpec[$id] = $r;
                }
                break;
            }
        }

        return $perSpec;
    }
}
