<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GameDataPeriod;
use App\Models\MetaSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MetaStatsController extends Controller
{
    public function periods(): JsonResponse
    {
        $payload = Cache::flexible('meta:periods', [270, 86400], function (): array {
            $periodIds = MetaSnapshot::query()
                ->select('period_id')
                ->distinct()
                ->orderByDesc('period_id')
                ->pluck('period_id');

            $dates = GameDataPeriod::query()
                ->whereIn('period_id', $periodIds)
                ->get()
                ->groupBy('period_id');

            $current = $periodIds->first();

            return $periodIds->map(function (int $id) use ($dates, $current): array {
                $rows = ($dates->get($id) ?? collect())->sortBy('region')->values();
                $row = $rows->first();

                $affixes = [];
                foreach ($rows as $r) {
                    if (! empty($r->affix_ids)) {
                        $affixes[$r->region] = array_map('intval', $r->affix_ids);
                    }
                }

                return [
                    'period_id' => $id,
                    'start_at' => $row?->start_at?->toIso8601String(),
                    'end_at' => $row?->end_at?->toIso8601String(),
                    'is_current' => $id === $current,
                    // Plain array in the cache: cache.serializable_classes=false
                    // turns a cached stdClass into __PHP_Incomplete_Class on hit.
                    'affixes' => $affixes,
                ];
            })->values()->all();
        });

        // Cast at response time so an empty affix set encodes as {} not [].
        $periods = array_map(
            fn (array $p): array => ['affixes' => (object) $p['affixes']] + $p,
            $payload,
        );

        return response()->json(['periods' => $periods]);
    }

    public function specs(Request $request): JsonResponse
    {
        return $this->section($request, 'specs');
    }

    public function comps(Request $request): JsonResponse
    {
        return $this->section($request, 'comps');
    }

    public function dungeons(Request $request): JsonResponse
    {
        return $this->section($request, 'dungeons', function (array $payload, int $periodId, string $region): array {
            $prior = MetaSnapshot::query()
                ->where('section', 'dungeons')
                ->where('region', $region)
                ->where('period_id', '<=', $periodId)
                ->orderByDesc('period_id')
                ->limit(8)
                ->get(['period_id', 'payload']);

            $trends = [];
            foreach ($prior->sortBy('period_id')->values() as $snap) {
                foreach ($snap->payload['dungeons'] ?? [] as $d) {
                    $trends[$d['dungeon_id']][] = ['period_id' => $snap->period_id, 'timed_rate' => $d['timed_rate']];
                }
            }
            $payload['trends'] = $trends;

            return $payload;
        });
    }

    private function section(Request $request, string $section, ?\Closure $decorate = null): JsonResponse
    {
        $region = strtolower((string) $request->input('region', 'all'));
        $validRegions = array_merge(['all'], config('blizzard.mplus_leaderboard.regions', ['eu', 'us']));
        if (! in_array($region, $validRegions, true)) {
            $region = 'all';
        }

        $periodId = $request->filled('period')
            ? (int) $request->input('period')
            : (int) MetaSnapshot::query()->where('region', $region)->max('period_id');

        if ($periodId === 0) {
            return response()->json(['status' => 'not_warmed'], 404);
        }

        // SWR: hot for 270s, stale entries survive 24h so an idle gap never
        // forces a synchronous recompute. Missing snapshots cache as null and
        // are recomputed on every hit, so a warm run shows up immediately.
        $cacheKey = "meta:{$section}:{$periodId}:{$region}";
        $payload = Cache::flexible($cacheKey, [270, 86400], function () use ($periodId, $region, $section, $decorate): ?array {
            $snapshot = MetaSnapshot::query()
                ->where('period_id', $periodId)
                ->where('region', $region)
                ->where('section', $section)
                ->first();

            if ($snapshot === null) {
                return null;
            }

            $payload = $snapshot->payload;
            $payload['computed_at'] = $snapshot->computed_at?->toIso8601String();

            return $decorate !== null ? $decorate($payload, $periodId, $region) : $payload;
        });

        if ($payload === null) {
            return response()->json(['status' => 'not_warmed'], 404);
        }

        return response()->json(array_merge(['period_id' => $periodId, 'region' => $region], $payload));
    }
}
