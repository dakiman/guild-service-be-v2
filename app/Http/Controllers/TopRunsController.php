<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DungeonRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TopRunsController extends Controller
{
    /**
     * Leaderboard depth. Pagination is clamped here so the endpoint never
     * exposes the full dungeon_runs table (~2.6M rows → 131k pages), and the
     * count query never scans past the cap.
     */
    private const LEADERBOARD_CAP = 100;

    public function __invoke(Request $request): JsonResponse
    {
        // max(1, ...): a negative per_page used to bypass the cap and return
        // the whole table (limit() ignores negatives → no LIMIT). (P1.11)
        $perPage = max(1, min((int) $request->input('per_page', 20), 50));
        $lastPage = (int) ceil(self::LEADERBOARD_CAP / $perPage);
        $page = min(max(1, (int) $request->input('page', 1)), $lastPage);
        $dungeonId = $request->filled('dungeon_id') ? (int) $request->input('dungeon_id') : null;

        $cacheKey = 'stats:top-runs:'.$page.':'.$perPage.':'.($dungeonId ?? 'all');

        // SWR like top-keys (B7). Cached value must be a plain array
        // (paginator->toArray()): cache.serializable_classes is false, so a
        // cached object would come back as __PHP_Incomplete_Class and 500.
        // Page/per_page/dungeon_id are resolved outside the closure because
        // the deferred stale-refresh runs after the response, and the key
        // must pin exactly what the closure computes.
        $payload = Cache::flexible($cacheKey, [270, 86400], function () use ($perPage, $page, $dungeonId) {
            $base = DungeonRun::query()->where('is_completed_on_time', true);

            if ($dungeonId !== null) {
                $base->where('dungeon_id', $dungeonId);
            }

            // Count inside the cap only — a plain count() scans every
            // matching row just to report a total we'd clamp anyway.
            $total = DB::query()
                ->fromSub($base->clone()->select('id')->limit(self::LEADERBOARD_CAP), 'capped')
                ->count();

            $offset = ($page - 1) * $perPage;
            // Last page: shrink the window so a per_page that doesn't divide
            // the cap evenly (e.g. 30 → 30/30/30/10) can't leak past rank 100.
            $limit = min($perPage, self::LEADERBOARD_CAP - $offset);

            $runs = $base->clone()
                ->orderByDesc('keystone_level')
                ->orderBy('duration')
                ->with('memberEntries.character:id,class_id')
                ->skip($offset)
                ->take($limit)
                ->get()
                ->map(function (DungeonRun $run) {
                    return [
                        'id' => $run->id,
                        'dungeon_id' => $run->dungeon_id,
                        'dungeon_name' => $run->dungeon_name,
                        'keystone_level' => $run->keystone_level,
                        'duration' => $run->duration,
                        'is_completed_on_time' => $run->is_completed_on_time,
                        'affixes' => $run->affixes,
                        'completed_at' => $run->completed_timestamp,
                        'members' => $run->memberEntries->map(fn ($m) => [
                            'name' => $m->character_name,
                            'realm' => $m->character_realm,
                            'region' => $m->character_region,
                            'spec_id' => $m->spec_id,
                            'spec_name' => $m->spec_name,
                            'class_id' => $m->character?->class_id,
                            'ilvl' => $m->equipped_item_level,
                        ])->all(),
                    ];
                });

            $paginator = new LengthAwarePaginator($runs->values()->all(), $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);

            return $paginator->toArray();
        });

        return response()->json($payload);
    }
}
