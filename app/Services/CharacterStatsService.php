<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Jobs\WarmCharacterStats;
use App\Models\Character;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CharacterStatsService
{
    public const CACHE_KEY = 'stats:characters';

    public function __construct(
        private readonly BlizzardGameDataClient $gameDataClient,
    ) {}

    /**
     * Serve whatever is cached, however old — the hourly WarmCharacterStats
     * job is the only writer. A completely empty cache (first deploy, Redis
     * flush) dispatches one refresh job (ShouldBeUnique dedupes concurrent
     * visitors) and serves an empty shape until the job lands — a web
     * request never runs the ~10s computeStats() in-process.
     */
    public function getStats(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        WarmCharacterStats::dispatch();

        return $this->emptyStats();
    }

    public function warm(): void
    {
        Cache::forever(self::CACHE_KEY, $this->computeStats());
    }

    /**
     * Must mirror exactly what computeStats() returns on an empty character
     * set — the FE renders this as its normal empty state.
     */
    private function emptyStats(): array
    {
        return [
            'total_characters' => 0,
            'class_distribution' => [],
            'spec_distribution' => [],
            'faction_distribution' => ['horde' => 0, 'alliance' => 0],
            'race_distribution' => [],
            'top_performers' => [
                'mythic_plus' => [],
                'item_level' => [],
                'achievement_points' => [],
            ],
            'avg_achievement_points' => 0,
            'most_popular_spec' => null,
        ];
    }

    /**
     * All aggregates run against a temp table materialized once per call —
     * the endgameActive() OR-of-EXISTS filter costs ~7s on prod and used to
     * be re-paid by every one of these queries (60-90s total).
     */
    private const TEMP_TABLE = 'stats_endgame_characters';

    private function computeStats(): array
    {
        $this->materializeEndgameActive();

        $specDistribution = $this->getSpecDistribution();

        return [
            'total_characters' => (int) DB::table(self::TEMP_TABLE)->count(),
            'class_distribution' => $this->getClassDistribution(),
            'spec_distribution' => $specDistribution,
            'faction_distribution' => $this->getFactionDistribution(),
            'race_distribution' => $this->getRaceDistribution(),
            'top_performers' => $this->getTopPerformers(),
            'avg_achievement_points' => $this->getAvgAchievementPoints(),
            'most_popular_spec' => $specDistribution[0] ?? null,
        ];
    }

    private function materializeEndgameActive(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::TEMP_TABLE);

        $base = Character::endgameActive()->select([
            'id', 'name', 'realm', 'region', 'class_id', 'race_id', 'faction',
            'active_specialization_id', 'average_item_level',
            'mythic_plus_rating', 'achievement_points',
        ]);

        // toRawSql(): CREATE TABLE AS is a utility statement — PG rejects
        // bind parameters in it, so bindings must be inlined (they are just
        // the integer endgame level).
        DB::statement(
            'CREATE TEMPORARY TABLE '.self::TEMP_TABLE.' AS '.$base->toRawSql()
        );
    }

    private function getClassDistribution(): array
    {
        return DB::table(self::TEMP_TABLE)
            ->selectRaw('class_id, COUNT(*) as count, ROUND(AVG(average_item_level), 1) as avg_ilvl, ROUND(AVG(mythic_plus_rating), 1) as avg_mythic_plus_rating')
            ->groupBy('class_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'class_id' => (int) $row->class_id,
                'count' => (int) $row->count,
                'avg_ilvl' => (float) $row->avg_ilvl,
                'avg_mythic_plus_rating' => (float) $row->avg_mythic_plus_rating,
            ])
            ->all();
    }

    private function getSpecDistribution(): array
    {
        return DB::table(self::TEMP_TABLE)
            ->selectRaw('active_specialization_id as spec_id, class_id, COUNT(*) as count')
            ->whereNotNull('active_specialization_id')
            ->groupBy('active_specialization_id', 'class_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'spec_id' => (int) $row->spec_id,
                'class_id' => (int) $row->class_id,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function getFactionDistribution(): array
    {
        $counts = DB::table(self::TEMP_TABLE)
            ->selectRaw('faction, COUNT(*) as count')
            ->groupBy('faction')
            ->pluck('count', 'faction');

        return [
            'horde' => (int) ($counts['Horde'] ?? 0),
            'alliance' => (int) ($counts['Alliance'] ?? 0),
        ];
    }

    private function getRaceDistribution(): array
    {
        return DB::table(self::TEMP_TABLE)
            ->selectRaw('race_id, COUNT(*) as count')
            ->groupBy('race_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'race_id' => (int) $row->race_id,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function getTopPerformers(int $limit = 5): array
    {
        $currentSeason = $this->gameDataClient->getCurrentMythicPlusSeason();

        $mythicPlusQuery = DB::table(self::TEMP_TABLE.' as c')
            ->whereExists(function ($q) use ($currentSeason) {
                $q->select(DB::raw(1))
                    ->from('dungeon_run_members as drm')
                    ->join('dungeon_runs as dr', 'dr.id', '=', 'drm.dungeon_run_id')
                    ->whereColumn('drm.character_id', 'c.id')
                    ->where('dr.season', $currentSeason);
            });

        return [
            'mythic_plus' => $this->getTopBy($mythicPlusQuery, 'mythic_plus_rating', $limit),
            'item_level' => $this->getTopBy(DB::table(self::TEMP_TABLE), 'average_item_level', $limit),
            'achievement_points' => $this->getTopBy(DB::table(self::TEMP_TABLE), 'achievement_points', $limit),
        ];
    }

    private function getTopBy(QueryBuilder $query, string $column, int $limit): array
    {
        return $query
            ->select(['name', 'realm', 'region', 'class_id', 'active_specialization_id', $column])
            ->whereNotNull($column)
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'realm' => $row->realm,
                'region' => $row->region,
                'class_id' => (int) $row->class_id,
                'spec_id' => $row->active_specialization_id ? (int) $row->active_specialization_id : null,
                'value' => (float) $row->$column,
            ])
            ->all();
    }

    private function getAvgAchievementPoints(): int
    {
        return (int) round((float) DB::table(self::TEMP_TABLE)->avg('achievement_points'));
    }
}
