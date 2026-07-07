<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RaidRetention;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RaidKillStatsService
{
    /** Difficulties the FE heatmap offers — kept warm by WarmRaidKillStats. */
    public const WARM_DIFFICULTIES = ['normal', 'heroic', 'mythic'];

    public function getByDifficulty(string $difficulty): array
    {
        $expansion = RaidRetention::currentExpansionName() ?? '';
        $cacheKey = "stats:raid-kills:{$difficulty}:{$expansion}";

        // SWR: fresh for 55 min, then stale-served (up to 60 min) while one
        // deferred refresh recomputes — the aggregate over raid_encounter_kills
        // must never run inline in a user request.
        return Cache::flexible($cacheKey, [3300, 3600], fn () => $this->compute($difficulty, $expansion));
    }

    public function warm(): void
    {
        $expansion = RaidRetention::currentExpansionName() ?? '';

        foreach (self::WARM_DIFFICULTIES as $difficulty) {
            // forget() first so flexible() recomputes and restamps its
            // created-at bookkeeping key instead of serving the old value.
            Cache::forget("stats:raid-kills:{$difficulty}:{$expansion}");
            $this->getByDifficulty($difficulty);
        }
    }

    private function compute(string $difficulty, string $expansion): array
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

        // Searched characters retain legacy rows, so the current-expansion
        // filter stays load-bearing even after the prune.
        if ($expansion !== '') {
            $query->where('raid_encounter_kills.expansion_name', $expansion);
        }

        $rows = $query->get();

        return [
            'raids' => $this->restructure($rows),
            'expansions' => $expansion === '' ? [] : [$expansion],
            'current_expansion' => $expansion === '' ? null : $expansion,
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
