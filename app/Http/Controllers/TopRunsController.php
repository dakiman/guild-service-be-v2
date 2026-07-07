<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DungeonRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TopRunsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // max(1, ...): a negative per_page used to bypass the cap and return
        // the whole table (limit() ignores negatives → no LIMIT). (P1.11)
        $perPage = max(1, min((int) $request->input('per_page', 20), 50));
        $page = max(1, (int) $request->input('page', 1));
        $dungeonId = $request->filled('dungeon_id') ? (int) $request->input('dungeon_id') : null;

        $cacheKey = 'stats:top-runs:'.$page.':'.$perPage.':'.($dungeonId ?? 'all');

        // SWR like top-keys (B7). Cached value must be a plain array
        // (paginator->toArray()): cache.serializable_classes is false, so a
        // cached object would come back as __PHP_Incomplete_Class and 500.
        // Page/per_page/dungeon_id are resolved outside the closure because
        // the deferred stale-refresh runs after the response, and the key
        // must pin exactly what the closure computes.
        $payload = Cache::flexible($cacheKey, [270, 86400], function () use ($perPage, $page, $dungeonId) {
            $query = DungeonRun::query()
                ->where('is_completed_on_time', true)
                ->orderByDesc('keystone_level')
                ->orderBy('duration')
                ->with('memberEntries.character:id,class_id');

            if ($dungeonId !== null) {
                $query->where('dungeon_id', $dungeonId);
            }

            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            $paginated->getCollection()->transform(function (DungeonRun $run) {
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

            return $paginated->toArray();
        });

        return response()->json($payload);
    }
}
