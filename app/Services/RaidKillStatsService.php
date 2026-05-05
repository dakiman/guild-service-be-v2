<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RaidKillStatsService
{
    public function getByDifficulty(string $difficulty): array
    {
        return Cache::remember("stats:raid-kills:{$difficulty}", 600, fn () => $this->compute($difficulty));
    }

    private function compute(string $difficulty): array
    {
        $rows = DB::table('raid_encounter_kills')
            ->join('characters', 'raid_encounter_kills.character_id', '=', 'characters.id')
            ->where('raid_encounter_kills.difficulty', $difficulty)
            ->where('characters.level', 80)
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
            )
            ->get();

        return ['raids' => $this->restructure($rows)];
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
