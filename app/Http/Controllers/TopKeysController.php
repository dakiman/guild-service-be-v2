<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DungeonRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TopKeysController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // SWR: serve the cached value for 270s, then serve stale (up to 300s)
        // while a single deferred refresh recomputes — avoids a cache stampede
        // where every request past expiry recomputes concurrently. (B7)
        $data = Cache::flexible('stats:top-keys', [270, 300], function () {
            // One row per dungeon via a window function (portable DISTINCT ON)
            // instead of loading every timed run into memory and grouping in PHP.
            // memberEntries + their characters are eager-loaded — no per-dungeon N+1.
            $ranked = DungeonRun::query()
                ->where('is_completed_on_time', true)
                // `id` tiebreaker keeps the chosen row deterministic across
                // re-queries (and thus the cache) when level+duration tie.
                ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY dungeon_id ORDER BY keystone_level DESC, duration ASC, id ASC) as rn');

            $topRuns = DungeonRun::query()
                ->fromSub($ranked, 'dungeon_runs')
                ->where('rn', 1)
                ->with('memberEntries.character:id,name,realm,region,class_id')
                ->orderByDesc('keystone_level')
                ->orderBy('duration')
                ->get();

            return [
                'dungeons' => $topRuns->map(function (DungeonRun $run) {
                    $member = $run->memberEntries->firstWhere('character_id', '!=', null);
                    $character = $member?->character;

                    return [
                        'dungeon_id' => $run->dungeon_id,
                        'dungeon_name' => $run->dungeon_name,
                        'key_level' => $run->keystone_level,
                        'duration' => $run->duration,
                        'character' => $character ? [
                            'name' => $character->name,
                            'realm' => $character->realm,
                            'region' => $character->region,
                            'class_id' => $character->class_id,
                        ] : null,
                    ];
                })->values()->all(),
            ];
        });

        return response()->json($data);
    }
}
