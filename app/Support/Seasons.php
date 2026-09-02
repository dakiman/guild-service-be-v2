<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GameDataSeason;
use Illuminate\Database\QueryException;
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

    private const ALL_CACHE_KEY = 'seasons:all';

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
        try {
            $cached = Cache::remember(self::CACHE_KEY, 3600, fn () => self::load());
        } catch (QueryException) {
            // Registry table missing entirely (unmigrated test DBs, the
            // deploy window before `php artisan migrate` runs) — same
            // fail-open contract as an empty registry.
            return null;
        }

        return $cached === self::NULL_SENTINEL ? null : $cached;
    }

    /** @return array{id: int, slug: string, name: string, raiderio_tier_slug: string, raiderio_expansion_id: int, expansion_id: ?int}|string */
    private static function load(): array|string
    {
        $row = GameDataSeason::where('is_current', true)->first();

        return $row === null ? self::NULL_SENTINEL : [
            'id' => (int) $row->id,
            'slug' => (string) $row->slug,
            'name' => (string) $row->name,
            'raiderio_tier_slug' => (string) $row->raiderio_tier_slug,
            'raiderio_expansion_id' => (int) $row->raiderio_expansion_id,
            'expansion_id' => $row->expansion_id === null ? null : (int) $row->expansion_id,
        ];
    }

    public static function currentId(): ?int
    {
        return self::current()['id'] ?? null;
    }

    /**
     * Every registry season keyed by id, newest first, as plain arrays
     * (same serializable_classes caveat as current()). Empty array when
     * the registry is empty or unmigrated.
     *
     * @return array<int, array{id: int, slug: string, name: string, is_current: bool, started_at: ?string, ended_at: ?string}>
     */
    public static function all(): array
    {
        try {
            // An empty array is a real value to Cache::remember (only null re-evaluates).
            return Cache::remember(self::ALL_CACHE_KEY, 3600, fn () => GameDataSeason::query()
                ->orderByDesc('id')
                ->get()
                ->mapWithKeys(fn (GameDataSeason $s) => [(int) $s->id => [
                    'id' => (int) $s->id,
                    'slug' => (string) $s->slug,
                    'name' => (string) $s->name,
                    'is_current' => (bool) $s->is_current,
                    'started_at' => $s->started_at?->toIso8601String(),
                    'ended_at' => $s->ended_at?->toIso8601String(),
                ]])
                ->all());
        } catch (QueryException) {
            return [];
        }
    }

    /** @return array{id: int, slug: string, name: string, is_current: bool, started_at: ?string, ended_at: ?string}|null */
    public static function byId(?int $id): ?array
    {
        return $id === null ? null : (self::all()[$id] ?? null);
    }

    /** @return array{id: int, slug: string, name: string, is_current: bool, started_at: ?string, ended_at: ?string}|null */
    public static function bySlug(string $slug): ?array
    {
        foreach (self::all() as $season) {
            if ($season['slug'] === $slug) {
                return $season;
            }
        }

        return null;
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
        Cache::forget(self::ALL_CACHE_KEY);
    }
}
