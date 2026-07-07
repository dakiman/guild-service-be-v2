<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RaidKillStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RaidKillStatsController extends Controller
{
    public function __invoke(Request $request, RaidKillStatsService $service): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'difficulty' => 'sometimes|in:lfr,normal,heroic,mythic',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $difficulty = $request->query('difficulty', 'heroic');

        return response()->json($service->getByDifficulty($difficulty));
    }
}
