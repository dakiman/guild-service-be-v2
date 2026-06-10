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
        $data = Cache::remember('stats:top-keys', 300, function () {
            $topRuns = DungeonRun::query()
                ->where('is_completed_on_time', true)
                ->orderByDesc('keystone_level')
                ->orderBy('duration')
                ->get()
                ->groupBy('dungeon_id')
                ->map(fn ($group) => $group->first());

            return [
                'dungeons' => $topRuns->map(function (DungeonRun $run) {
                    $member = $run->memberEntries()->whereNotNull('character_id')->first();
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
