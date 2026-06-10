<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CharacterStatsService;
use Illuminate\Http\JsonResponse;

class CharacterStatsController extends Controller
{
    public function __invoke(CharacterStatsService $service): JsonResponse
    {
        return response()->json($service->getStats());
    }
}
