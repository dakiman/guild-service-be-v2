<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MythicPlusLeaderboards;
use App\Support\Seasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TopKeysController extends Controller
{
    public function __invoke(MythicPlusLeaderboards $leaderboards): JsonResponse
    {
        $season = Seasons::currentId();

        // SWR: serve the cached value for 270s, then stale entries survive 24h
        // so idle gaps never cause a synchronous recompute. Season id in the
        // key so a rollover can never serve a stale cross-season mix. (B7)
        $data = Cache::flexible('stats:top-keys:'.($season ?? 'all'), [270, 86400], function () use ($leaderboards, $season) {
            return ['dungeons' => $leaderboards->topKeys($season)];
        });

        return response()->json($data);
    }
}
