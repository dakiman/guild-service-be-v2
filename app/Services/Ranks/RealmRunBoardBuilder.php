<?php

declare(strict_types=1);

namespace App\Services\Ranks;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\LadderRunMember;
use App\Models\RealmRunBoard;
use App\Support\BlizzardIdentity;
use App\Support\SpecClasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RealmRunBoardBuilder
{
    public const BOARD_SIZE = 20;

    /**
     * Top runs per connected realm for one (region, period). A run belongs to
     * every connected realm any of its members is from (cross-realm groups
     * appear on each). One window query, then members loaded for the ≤20×N
     * surviving runs only.
     */
    public function build(string $region, int $periodId, Carbon $computedAt): int
    {
        $rows = DB::select(<<<'SQL'
WITH run_realms AS (
    SELECT DISTINCT lm.ladder_run_id, m.connected_realm_id
    FROM ladder_runs r
    JOIN ladder_run_members lm ON lm.ladder_run_id = r.id
    JOIN realm_slug_map m ON m.region = r.region AND m.realm_slug = lm.realm_slug
    WHERE r.period_id = ? AND r.region = ? AND r.is_completed_on_time = ?
),
ranked AS (
    SELECT rr.connected_realm_id, r.id, r.dungeon_id, r.keystone_level, r.duration,
           r.completed_timestamp, r.is_completed_on_time,
           ROW_NUMBER() OVER (PARTITION BY rr.connected_realm_id
                              ORDER BY r.keystone_level DESC, r.duration ASC, r.id ASC) AS rn
    FROM run_realms rr
    JOIN ladder_runs r ON r.id = rr.ladder_run_id
)
SELECT * FROM ranked WHERE rn <= ? ORDER BY connected_realm_id, rn
SQL, [$periodId, $region, true, self::BOARD_SIZE]);

        if ($rows === []) {
            RealmRunBoard::query()->where('region', $region)->where('period_id', $periodId)->delete();

            return 0;
        }

        $runIds = array_values(array_unique(array_map(fn ($r) => (int) $r->id, $rows)));
        $members = LadderRunMember::query()
            ->whereIn('ladder_run_id', $runIds)
            ->orderBy('id')
            ->get()
            ->groupBy('ladder_run_id');
        $dungeonNames = GameDataMythicKeystoneDungeon::query()->pluck('name', 'id');

        $boards = [];
        foreach ($rows as $r) {
            $boards[(int) $r->connected_realm_id][] = [
                'id' => (int) $r->id,
                'dungeon_id' => (int) $r->dungeon_id,
                'dungeon_name' => (string) ($dungeonNames[(int) $r->dungeon_id] ?? 'Unknown'),
                'keystone_level' => (int) $r->keystone_level,
                'duration' => (int) $r->duration,
                'is_completed_on_time' => (bool) $r->is_completed_on_time,
                'affixes' => [],
                'completed_at' => (int) $r->completed_timestamp,
                'members' => ($members[(int) $r->id] ?? collect())->map(fn (LadderRunMember $m) => [
                    'name' => BlizzardIdentity::name($m->name),
                    'realm' => $m->realm_slug,
                    'region' => $region,
                    'spec_id' => $m->spec_id,
                    'spec_name' => null,
                    'class_id' => SpecClasses::classFor($m->spec_id),
                    'ilvl' => null,
                ])->values()->all(),
            ];
        }

        DB::transaction(function () use ($boards, $region, $periodId, $computedAt) {
            RealmRunBoard::query()->where('region', $region)->where('period_id', $periodId)->delete();
            foreach ($boards as $connectedRealmId => $payload) {
                RealmRunBoard::create([
                    'period_id' => $periodId,
                    'region' => $region,
                    'connected_realm_id' => $connectedRealmId,
                    'payload' => $payload,
                    'computed_at' => $computedAt,
                ]);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ANALYZE realm_run_boards');
        }

        return count($boards);
    }
}
