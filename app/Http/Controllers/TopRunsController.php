<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DungeonRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopRunsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // max(1, ...): a negative per_page used to bypass the cap and return
        // the whole table (limit() ignores negatives → no LIMIT). (P1.11)
        $perPage = max(1, min((int) $request->input('per_page', 20), 50));

        $query = DungeonRun::query()
            ->where('is_completed_on_time', true)
            ->orderByDesc('keystone_level')
            ->orderBy('duration')
            ->with('memberEntries.character:id,class_id');

        if ($request->filled('dungeon_id')) {
            $query->where('dungeon_id', (int) $request->input('dungeon_id'));
        }

        $paginated = $query->paginate($perPage);

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
                ]),
            ];
        });

        return response()->json($paginated);
    }
}
