<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GameDataSeason;
use Illuminate\Support\Facades\Cache;

class Seasons
{
    private const CACHE_KEY = 'seasons:current';

    /**
     * Cached in place of null so Cache::remember treats "no current season"
     * as a real hit (it re-evaluates the closure whenever the cached value
     * is null). Same convention as BlizzardGameDataClient::NULL_SENTINEL.
     */
    private const NULL_SENTINEL = '__none__';

    /**
     * The current season as a plain array (never a Model —
     * cache.serializable_classes is false, so cached objects come back as
     * __PHP_Incomplete_Class). Null when the registry is empty; callers
     * MUST fail open to their pre-registry behavior.
     *
     * @return array{id: int, slug: string, name: string, raiderio_tier_slug: string, raiderio_expansion_id: int, expansion_id: ?int}|null
     */
    public static function current(): ?array
    {
        $cached = Cache::remember(self::CACHE_KEY, 3600, function () {
            $row = GameDataSeason::where('is_current', true)->first();

            return $row === null ? self::NULL_SENTINEL : [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'raiderio_tier_slug' => (string) $row->raiderio_tier_slug,
                'raiderio_expansion_id' => (int) $row->raiderio_expansion_id,
                'expansion_id' => $row->expansion_id === null ? null : (int) $row->expansion_id,
            ];
        });

        return $cached === self::NULL_SENTINEL ? null : $cached;
    }

    public static function currentId(): ?int
    {
        return self::current()['id'] ?? null;
    }

    public static function raiderioSeasonSlug(): string
    {
        return self::current()['slug'] ?? (string) config('raiderio.season');
    }

    public static function raiderioTierSlug(): string
    {
        return self::current()['raiderio_tier_slug'] ?? (string) config('raiderio.current_raid_tier', 'tier-mn-1');
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
