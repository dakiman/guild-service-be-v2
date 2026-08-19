<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\MetaSnapshot;
use App\Support\KeystoneTimers;
use App\Support\SpecRoles;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MetaStatsService
{
    public const SECTIONS = ['specs', 'dungeons', 'comps'];

    /** Minimum runs a dungeon needs before it can be "dungeon of the week". */
    private const DOTW_MIN_RUNS = 50;

    public function warm(int $periodId): void
    {
        $scopes = array_merge(['all'], config('blizzard.mplus_leaderboard.regions', ['eu', 'us']));

        foreach ($scopes as $region) {
            $this->store($periodId, $region, 'specs', $this->computeSpecs($periodId, $region));
            $this->store($periodId, $region, 'dungeons', $this->computeDungeons($periodId, $region));
            $this->store($periodId, $region, 'comps', $this->computeComps($periodId, $region));
        }
    }

    public function computeSpecs(int $periodId, string $region): array
    {
        $brackets = config('blizzard.mplus_leaderboard.brackets', [0, 7, 12, 17]);
        $out = ['brackets' => []];

        foreach ($brackets as $minLevel) {
            $rows = DB::table('ladder_run_members as m')
                ->join('ladder_runs as r', 'r.id', '=', 'm.ladder_run_id')
                ->where('r.period_id', $periodId)
                ->when($region !== 'all', fn ($q) => $q->where('r.region', $region))
                ->when($minLevel > 0, fn ($q) => $q->where('r.keystone_level', '>=', $minLevel))
                ->whereNotNull('m.spec_id')
                ->groupBy('m.spec_id')
                ->selectRaw('m.spec_id, COUNT(*) as appearances, SUM(CASE WHEN r.is_completed_on_time THEN 1 ELSE 0 END) as timed, COUNT(r.is_completed_on_time) as judged')
                ->get();

            $roles = ['tank' => [], 'healer' => [], 'dps' => []];
            $roleTotals = ['tank' => 0, 'healer' => 0, 'dps' => 0];
            foreach ($rows as $row) {
                $role = SpecRoles::roleFor((int) $row->spec_id);
                if ($role === null) {
                    continue;
                }
                $roles[$role][] = $row;
                $roleTotals[$role] += (int) $row->appearances;
            }

            $totalRuns = (int) $this->baseRuns($periodId, $region)
                ->when($minLevel > 0, fn ($q) => $q->where('keystone_level', '>=', $minLevel))
                ->count();

            $bracketKey = $minLevel === 0 ? 'all' : (string) $minLevel;
            $bracketOut = ['roles' => [], 'total_runs' => $totalRuns];
            foreach ($roles as $role => $entries) {
                usort($entries, fn ($a, $b) => $b->appearances <=> $a->appearances);
                $bracketOut['roles'][$role] = array_map(fn ($e): array => [
                    'spec_id' => (int) $e->spec_id,
                    'count' => (int) $e->appearances,
                    'share' => $roleTotals[$role] > 0 ? round($e->appearances / $roleTotals[$role], 4) : 0.0,
                    'timed_rate' => $e->judged > 0 ? round($e->timed / $e->judged, 4) : 0.0,
                ], $entries);
            }
            $out['brackets'][$bracketKey] = $bracketOut;
        }

        return $out;
    }

    public function computeDungeons(int $periodId, string $region): array
    {
        $rows = $this->baseRuns($periodId, $region)
            ->groupBy('dungeon_id')
            ->selectRaw('dungeon_id, COUNT(*) as runs, SUM(CASE WHEN is_completed_on_time THEN 1 ELSE 0 END) as timed, COUNT(is_completed_on_time) as judged, AVG(keystone_level) as avg_key, AVG(duration) as avg_duration, MAX(keystone_level) as highest_key')
            ->get();

        $dungeons = GameDataMythicKeystoneDungeon::query()
            ->findMany($rows->pluck('dungeon_id'))
            ->keyBy('id');

        $entries = $rows->map(function ($row) use ($dungeons): array {
            $dungeon = $dungeons->get((int) $row->dungeon_id);
            $timerMs = KeystoneTimers::plusOne($dungeon?->keystone_upgrades);
            $avgDuration = (int) round((float) $row->avg_duration);

            return [
                'dungeon_id' => (int) $row->dungeon_id,
                'name' => $dungeon?->name,
                'runs' => (int) $row->runs,
                'timed_rate' => $row->judged > 0 ? round($row->timed / $row->judged, 4) : 0.0,
                'avg_key' => round((float) $row->avg_key, 1),
                'avg_duration_ms' => $avgDuration,
                'timer_ms' => $timerMs,
                'avg_margin_ms' => $timerMs !== null ? $timerMs - $avgDuration : null,
                'highest_key' => (int) $row->highest_key,
            ];
        })->sortByDesc('runs')->values()->all();

        $pick = collect($entries)
            ->filter(fn (array $e): bool => $e['runs'] >= min(self::DOTW_MIN_RUNS, collect($entries)->max('runs') ?? 0))
            ->sortBy([['timed_rate', 'desc'], ['avg_key', 'desc']])
            ->first();

        return ['dungeons' => $entries, 'dungeon_of_the_week' => $pick['dungeon_id'] ?? null];
    }

    public function computeComps(int $periodId, string $region): array
    {
        $minSample = (int) config('blizzard.mplus_leaderboard.comp_min_sample', 25);

        $rows = $this->baseRuns($periodId, $region)
            ->whereNotNull('comp_signature')
            ->groupBy('comp_signature')
            ->selectRaw('comp_signature, COUNT(*) as runs, SUM(CASE WHEN is_completed_on_time THEN 1 ELSE 0 END) as timed, COUNT(is_completed_on_time) as judged')
            ->get();

        $pairings = [];
        foreach ($rows as $row) {
            [$tank, $healer] = explode(':', $row->comp_signature);
            $key = "{$tank}:{$healer}";
            $pairings[$key] ??= ['tank_spec_id' => (int) $tank, 'healer_spec_id' => (int) $healer, 'count' => 0, 'timed' => 0, 'judged' => 0];
            $pairings[$key]['count'] += (int) $row->runs;
            $pairings[$key]['timed'] += (int) $row->timed;
            $pairings[$key]['judged'] += (int) $row->judged;
        }

        $comps = $rows
            ->filter(fn ($row): bool => (int) $row->runs >= $minSample)
            ->sortByDesc('runs')
            ->take(50)
            ->map(function ($row): array {
                [$tank, $healer, $dps] = explode(':', $row->comp_signature);

                return [
                    'signature' => $row->comp_signature,
                    'tank_spec_id' => (int) $tank,
                    'healer_spec_id' => (int) $healer,
                    'dps_spec_ids' => array_map('intval', explode(',', $dps)),
                    'count' => (int) $row->runs,
                    'timed_rate' => $row->judged > 0 ? round($row->timed / $row->judged, 4) : 0.0,
                ];
            })
            ->values()
            ->all();

        $pairingsOut = collect($pairings)
            ->filter(fn (array $p): bool => $p['count'] >= $minSample)
            ->sortByDesc('count')
            ->take(20)
            ->map(fn (array $p): array => [
                'tank_spec_id' => $p['tank_spec_id'],
                'healer_spec_id' => $p['healer_spec_id'],
                'count' => $p['count'],
                'timed_rate' => $p['judged'] > 0 ? round($p['timed'] / $p['judged'], 4) : 0.0,
            ])
            ->values()
            ->all();

        return ['comps' => $comps, 'pairings' => $pairingsOut, 'min_sample' => $minSample];
    }

    private function baseRuns(int $periodId, string $region): Builder
    {
        return DB::table('ladder_runs')
            ->where('period_id', $periodId)
            ->when($region !== 'all', fn ($q) => $q->where('region', $region));
    }

    private function store(int $periodId, string $region, string $section, array $payload): void
    {
        MetaSnapshot::query()->updateOrCreate(
            ['period_id' => $periodId, 'region' => $region, 'section' => $section],
            ['payload' => $payload, 'computed_at' => now()],
        );
    }
}
