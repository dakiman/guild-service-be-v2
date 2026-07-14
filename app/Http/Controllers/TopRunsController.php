<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MythicPlusLeaderboards;
use App\Support\Seasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class TopRunsController extends Controller
{
    public function __invoke(Request $request, MythicPlusLeaderboards $leaderboards): JsonResponse
    {
        // max(1, ...): a negative per_page used to bypass the cap and return
        // the whole table (limit() ignores negatives → no LIMIT). (P1.11)
        $perPage = max(1, min((int) $request->input('per_page', 20), 50));
        $lastPage = (int) ceil(MythicPlusLeaderboards::LEADERBOARD_CAP / $perPage);
        $page = min(max(1, (int) $request->input('page', 1)), $lastPage);
        $dungeonId = $request->filled('dungeon_id') ? (int) $request->input('dungeon_id') : null;
        $season = Seasons::currentId();

        // Season id in the key so a rollover can never serve a stale
        // cross-season mix; 'all' = empty-registry fail-open.
        $cacheKey = 'stats:top-runs:'.($season ?? 'all').':'.$page.':'.$perPage.':'.($dungeonId ?? 'all');

        // SWR like top-keys (B7). Cached value must be a plain array
        // (paginator->toArray()): cache.serializable_classes is false, so a
        // cached object would come back as __PHP_Incomplete_Class and 500.
        // Page/per_page/dungeon_id/season are resolved outside the closure
        // because the deferred stale-refresh runs after the response, and
        // the key must pin exactly what the closure computes.
        $payload = Cache::flexible($cacheKey, [270, 86400], function () use ($leaderboards, $season, $perPage, $page, $dungeonId) {
            $total = $leaderboards->cappedTotal($season, $dungeonId);

            $offset = ($page - 1) * $perPage;
            // Last page: shrink the window so a per_page that doesn't divide
            // the cap evenly (e.g. 30 → 30/30/30/10) can't leak past rank 100.
            $limit = min($perPage, MythicPlusLeaderboards::LEADERBOARD_CAP - $offset);

            $runs = $leaderboards->topRuns($season, $offset, $limit, $dungeonId);

            $paginator = new LengthAwarePaginator($runs, $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);

            return $paginator->toArray();
        });

        return response()->json($payload);
    }
}
