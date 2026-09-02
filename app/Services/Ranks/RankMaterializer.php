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

    private function populationWhere(): string
    {
        return "c.game_version = 'retail' AND c.level >= ? AND c.mythic_plus_rating > 0 AND c.rating_synced_at >= ?";
    }

    /** @return array{0: int, 1: string} */
    private function populationBindings(Carbon $startedAt): array
    {
        return [(int) config('blizzard.endgame_level', 90), $startedAt->format('Y-m-d H:i:s')];
    }

    public function populationCount(): int
    {
        $season = $this->seasonStart();
        if ($season === null) {
            return 0;
        }

        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM characters c WHERE '.$this->populationWhere(),
            $this->populationBindings($season['started_at']),
        )->n;
    }

    /**
     * Full rebuild inside one transaction. RANK() = competition ranking (ties
     * share, next skips). CASE wraps the realm/spec windows so unmapped realms
     * and null specs get NULL instead of being ranked inside a NULL partition.
     *
     * @return array{ranked: int, unmapped: int, per_region: array<string, int>}
     */
    public function materialize(Carbon $computedAt): array
    {
        $season = $this->seasonStart();
        if ($season === null) {
            throw new \RuntimeException('No current season with a started_at — cannot rank.');
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
            [$season['season_id'], $computedAt->format('Y-m-d H:i:s')],
            $this->populationBindings($season['started_at']),
        );

        DB::transaction(function () use ($sql, $bindings) {
            CharacterRank::query()->delete();
            DB::insert($sql, $bindings);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ANALYZE character_ranks');
        }

        Cache::forever(self::STAMP_KEY, $computedAt->toIso8601String());

        $perRegion = CharacterRank::query()
            ->selectRaw('region, COUNT(*) AS n')
            ->groupBy('region')
            ->pluck('n', 'region')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'ranked' => (int) CharacterRank::count(),
            'unmapped' => (int) CharacterRank::whereNull('connected_realm_id')->count(),
            'per_region' => $perRegion,
        ];
    }
}
