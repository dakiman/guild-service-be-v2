<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GameDataExpansion;
use Illuminate\Support\Facades\Cache;

class RaidRetention
{
    /**
     * Blizzard's raids payload groups current-season raids under this
     * pseudo-expansion name in addition to the real expansion entry.
     */
    public const CURRENT_SEASON = 'Current Season';

    public static function currentExpansionName(): ?string
    {
        return Cache::remember(
            'raids:current-expansion-name',
            3600,
            fn () => GameDataExpansion::orderBy('display_order')->value('name'),
        );
    }

    /**
     * Expansion names background sync lanes are allowed to persist.
     * Null = current expansion unknown (empty game_data_expansions):
     * callers MUST fail open and retain everything.
     */
    public static function expansions(): ?array
    {
        $current = self::currentExpansionName();

        return $current === null ? null : [$current, self::CURRENT_SEASON];
    }
}
