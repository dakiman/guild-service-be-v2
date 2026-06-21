<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Guild;
use App\Services\GuildStatsService;
use App\Support\BlizzardIdentity;
use Illuminate\Http\JsonResponse;

class GuildStatsController extends Controller
{
    public function __invoke(string $region, string $realm, string $guild, GuildStatsService $service): JsonResponse
    {
        // Normalize identically to GuildController::show so a URL that resolves
        // the guild page also resolves its stats instead of 404ing. (P1.4)
        $realm = BlizzardIdentity::realm($realm);
        $guild = BlizzardIdentity::realm($guild);

        $model = Guild::byIdentity($guild, $realm, $region)->first();

        if (! $model) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        return response()->json($service->getStats($model));
    }
}
