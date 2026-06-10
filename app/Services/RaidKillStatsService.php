<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GameDataExpansion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RaidKillStatsService
{
    public function getByDifficulty(string $difficulty, string $expansion = 'current'): array
    {
        $resolvedExpansion = $this->resolveExpansion($expansion);
        $cacheKey = "stats:raid-kills:{$difficulty}:{$resolvedExpansion}";

        return Cache::remember($cacheKey, 600, fn () => $this->compute($difficulty, $resolvedExpansion));
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
        return Cache::remember('stats:raid-kills:expansions', 600, function () {
            return DB::table('raid_encounter_kills')
                ->select('expansion_name')
                ->distinct()
                ->orderBy('expansion_name')
                ->pluck('expansion_name')
                ->all();
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
