<?php

declare(strict_types=1);

namespace App\Services\Ranks;

use App\Models\CharacterRank;
use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RankMaterializer
{
    public const STAMP_KEY = 'ranks:computed_at';

    /** @return array{season_id: int, started_at: Carbon}|null */
    public function seasonStart(): ?array
    {
        $current = Seasons::current();
        if ($current === null) {
            return null;
        }
        $startedAt = GameDataSeason::query()->whereKey($current['id'])->value('started_at');
        if ($startedAt === null) {
            return null;
        }

        return ['season_id' => (int) $current['id'], 'started_at' => Carbon::parse($startedAt)];
    }

    /** The season to rank — null when the registry is empty. */
    public function currentSeasonId(): ?int
    {
        return Seasons::currentId();
    }

    /**
     * Only ratings tagged with the current season are ranked — Blizzard
     * keeps reporting the last-played season's rating after a rollover, so
     * rating_synced_at alone cannot tell them apart (spec §A).
     */
    private function populationWhere(): string
    {
        return "c.game_version = 'retail' AND c.level >= ? AND c.mythic_plus_rating > 0 AND c.rating_season_id = ?";
    }

    /** @return array{0: int, 1: int} */
    private function populationBindings(int $seasonId): array
    {
        return [(int) config('blizzard.endgame_level', 90), $seasonId];
    }

    public function populationCount(): int
    {
        $seasonId = $this->currentSeasonId();
        if ($seasonId === null) {
            return 0;
        }

        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM characters c WHERE '.$this->populationWhere(),
            $this->populationBindings($seasonId),
        )->n;
    }

    /**
     * Full rebuild inside one transaction. RANK() = competition ranking (ties
     * share, next skips). CASE wraps the realm/spec windows so unmapped realms
     * and null specs get NULL instead of being ranked inside a NULL partition.
     *
     * @return array{season_id: int, ranked: int, unmapped: int, per_region: array<string, int>}
     */
    public function materialize(Carbon $computedAt): array
    {
        $seasonId = $this->currentSeasonId();
        if ($seasonId === null) {
            throw new \RuntimeException('No current season in game_data_seasons — cannot rank.');
        }

        $sql = <<<'SQL'
INSERT INTO character_ranks (
    character_id, season_id, region, connected_realm_id, class_id, spec_id, rating,
    world_rank, region_rank, realm_rank, class_rank, spec_rank,
    world_pop, region_pop, realm_pop, class_pop, spec_pop, computed_at
)
SELECT
    c.id, ?, c.region, m.connected_realm_id, c.class_id, c.active_specialization_id, c.mythic_plus_rating,
    RANK() OVER (ORDER BY c.mythic_plus_rating DESC),
    RANK() OVER (PARTITION BY c.region ORDER BY c.mythic_plus_rating DESC),
    CASE WHEN m.connected_realm_id IS NULL THEN NULL
         ELSE RANK() OVER (PARTITION BY c.region, m.connected_realm_id ORDER BY c.mythic_plus_rating DESC) END,
    RANK() OVER (PARTITION BY c.region, c.class_id ORDER BY c.mythic_plus_rating DESC),
    CASE WHEN c.active_specialization_id IS NULL THEN NULL
         ELSE RANK() OVER (PARTITION BY c.region, c.active_specialization_id ORDER BY c.mythic_plus_rating DESC) END,
    COUNT(*) OVER (),
    COUNT(*) OVER (PARTITION BY c.region),
    CASE WHEN m.connected_realm_id IS NULL THEN NULL
         ELSE COUNT(*) OVER (PARTITION BY c.region, m.connected_realm_id) END,
    COUNT(*) OVER (PARTITION BY c.region, c.class_id),
    CASE WHEN c.active_specialization_id IS NULL THEN NULL
         ELSE COUNT(*) OVER (PARTITION BY c.region, c.active_specialization_id) END,
    ?
FROM characters c
LEFT JOIN realm_slug_map m ON m.region = c.region AND m.realm_slug = c.realm
WHERE
SQL;
        $sql .= ' '.$this->populationWhere();

        $bindings = array_merge(
            [$seasonId, $computedAt->format('Y-m-d H:i:s')],
            $this->populationBindings($seasonId),
        );

        // Only this season's rows are replaced — older seasons stay frozen
        // (their last materialization ran inside season:rollover).
        DB::transaction(function () use ($sql, $bindings, $seasonId) {
            CharacterRank::query()->where('season_id', $seasonId)->delete();
            DB::insert($sql, $bindings);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ANALYZE character_ranks');
        }

        Cache::forever(self::STAMP_KEY, $computedAt->toIso8601String());

        $current = CharacterRank::query()->where('season_id', $seasonId);

        $perRegion = (clone $current)
            ->selectRaw('region, COUNT(*) AS n')
            ->groupBy('region')
            ->pluck('n', 'region')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'season_id' => $seasonId,
            'ranked' => (int) (clone $current)->count(),
            'unmapped' => (int) (clone $current)->whereNull('connected_realm_id')->count(),
            'per_region' => $perRegion,
        ];
    }
}
