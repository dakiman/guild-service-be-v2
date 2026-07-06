<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GameDataExpansion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RaidKillStatsService
{
    /** Difficulties the FE heatmap offers — kept warm by WarmRaidKillStats. */
    public const WARM_DIFFICULTIES = ['normal', 'heroic', 'mythic'];

    public function getByDifficulty(string $difficulty, string $expansion = 'current'): array
    {
        $resolvedExpansion = $this->resolveExpansion($expansion);
        $cacheKey = "stats:raid-kills:{$difficulty}:{$resolvedExpansion}";

        // SWR: fresh for 55 min, then stale-served (up to 60 min) while one
        // deferred refresh recomputes — the ~5s aggregate over 96M-row
        // raid_encounter_kills must never run inline in a user request.
        return Cache::flexible($cacheKey, [3300, 3600], fn () => $this->compute($difficulty, $resolvedExpansion));
    }

    public function warm(): void
    {
        $resolvedExpansion = $this->resolveExpansion('current');

        foreach (self::WARM_DIFFICULTIES as $difficulty) {
            // forget() first so flexible() recomputes and restamps its
            // created-at bookkeeping key instead of serving the old value.
            Cache::forget("stats:raid-kills:{$difficulty}:{$resolvedExpansion}");
            $this->getByDifficulty($difficulty);
        }
    }

    private function resolveExpansion(string $expansion): string
    {
        if ($expansion === 'current') {
            return $this->getCurrentExpansionName() ?? '';
        }

        return $expansion;
    }

    private function getCurrentExpansionName(): ?string
    {
        return GameDataExpansion::orderBy('display_order')->value('name');
    }

    private function getAvailableExpansions(): array
    {
        // Loose index scan: Postgres can't skip-scan a btree for DISTINCT, so
        // a plain SELECT DISTINCT walks all ~96M rows (~20s). Chained MIN()
        // probes on the (expansion_name, difficulty) index return the same
        // list in milliseconds; the list only changes when a new expansion's
        // kills first appear, hence the 24h TTL.
        return Cache::remember('stats:raid-kills:expansions', 86400, function () {
            $rows = DB::select(<<<'SQL'
                WITH RECURSIVE exps(name) AS (
                    SELECT MIN(expansion_name) FROM raid_encounter_kills
                    UNION ALL
                    SELECT (SELECT MIN(expansion_name) FROM raid_encounter_kills WHERE expansion_name > exps.name)
                    FROM exps WHERE exps.name IS NOT NULL
                )
                SELECT name FROM exps WHERE name IS NOT NULL ORDER BY name
            SQL);

            return array_map(fn (object $row) => $row->name, $rows);
        });
    }

    private function compute(string $difficulty, string $resolvedExpansion): array
    {
        $query = DB::table('raid_encounter_kills')
            ->join('characters', 'raid_encounter_kills.character_id', '=', 'characters.id')
            ->where('raid_encounter_kills.difficulty', $difficulty)
            ->select([
                'raid_encounter_kills.instance_id',
                'raid_encounter_kills.instance_name',
                'raid_encounter_kills.encounter_id',
                'raid_encounter_kills.encounter_name',
                'characters.class_id',
            ])
            ->selectRaw('SUM(raid_encounter_kills.completed_count) as total_kills')
            ->groupBy(
                'raid_encounter_kills.instance_id',
                'raid_encounter_kills.instance_name',
                'raid_encounter_kills.encounter_id',
                'raid_encounter_kills.encounter_name',
                'characters.class_id',
            );

        if ($resolvedExpansion !== '') {
            $query->where('raid_encounter_kills.expansion_name', $resolvedExpansion);
        }

        $rows = $query->get();

        return [
            'raids' => $this->restructure($rows),
            'expansions' => $this->getAvailableExpansions(),
            'current_expansion' => $this->getCurrentExpansionName(),
        ];
    }

    private function restructure(object $rows): array
    {
        $raids = [];

        foreach ($rows as $row) {
            $instanceId = (int) $row->instance_id;
            $encounterId = (int) $row->encounter_id;
            $classId = (string) $row->class_id;

            if (! isset($raids[$instanceId])) {
                $raids[$instanceId] = [
                    'instance_id' => $instanceId,
                    'name' => $row->instance_name,
                    'bosses' => [],
                ];
            }

            if (! isset($raids[$instanceId]['bosses'][$encounterId])) {
                $raids[$instanceId]['bosses'][$encounterId] = [
                    'encounter_id' => $encounterId,
                    'name' => $row->encounter_name,
                    'kills_by_class' => [],
                ];
            }

            $raids[$instanceId]['bosses'][$encounterId]['kills_by_class'][$classId] = (int) $row->total_kills;
        }

        // Re-index nested arrays to sequential
        foreach ($raids as &$raid) {
            $raid['bosses'] = array_values($raid['bosses']);
        }

        return array_values($raids);
    }
}
