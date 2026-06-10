<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\PvpBracketStats;

class PvpBracketStatsMapper
{
    /** Robust to query strings and any future slug form. */
    public function extractSlug(string $href): ?string
    {
        return preg_match('#/pvp-bracket/([^/?]+)#', $href, $m) ? $m[1] : null;
    }

    public function map(string $slug, ?array $body): ?PvpBracketStats
    {
        if ($body === null) {
            return null;
        }

        $s = $body['season_match_statistics'] ?? [];
        $w = $body['weekly_match_statistics'] ?? [];

        return new PvpBracketStats(
            bracket: $slug,
            rating: (int) ($body['rating'] ?? 0),
            seasonWon: (int) ($s['won'] ?? 0),
            seasonLost: (int) ($s['lost'] ?? 0),
            seasonPlayed: (int) ($s['played'] ?? 0),
            weeklyWon: (int) ($w['won'] ?? 0),
            weeklyLost: (int) ($w['lost'] ?? 0),
            weeklyPlayed: (int) ($w['played'] ?? 0),
            tierName: null,
        );
    }
}
