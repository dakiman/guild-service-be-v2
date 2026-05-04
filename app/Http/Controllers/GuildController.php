<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Jobs\SyncGuildData;
use App\Exceptions\EntityNotFoundException;
use App\Http\Resources\GuildMemberResource;
use App\Http\Resources\GuildResource;
use App\Http\Resources\GuildSuggestionResource;
use App\Http\Resources\GuildSummaryResource;
use App\Models\Guild;
use App\Services\GuildService;
use App\Support\BlizzardIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuildController extends Controller
{
    public function show(string $region, string $realm, string $guild, GuildService $service, Request $request): JsonResponse
    {
        $realm = BlizzardIdentity::realm($realm);
        $guild = BlizzardIdentity::realm($guild);

        try {
            $result = $service->getByIdentity($region, $realm, $guild);
        } catch (EntityNotFoundException) {
            return response()->json(['message' => 'Guild not found'], 404);
        }

        if ($result === null) {
            SyncGuildData::dispatch($region, $realm, $guild);

            return response()->json(['message' => 'Guild sync initiated'], 202)
                ->header('Retry-After', '5');
        }

        $perPage = (int) $request->query('per_page', '50');
        $members = $result->members()
            ->with(['character:id,equipped_item_level,mythic_plus_rating,mythic_plus_rating_color,active_specialization_id,updated_at'])
            ->paginate($perPage)
            ->through(fn ($member) => (new GuildMemberResource($member))->toArray($request));

        $response = response()->json([
            'guild' => new GuildResource($result),
            'members' => $members,
        ]);

        if ($result->isStale()) {
            $response->header('X-Data-Staleness', 'stale');
        }

        return $response;
    }

    public function popular(GuildService $service): JsonResponse
    {
        $data = $service->getPopular();

        return response()->json([
            'recently_searched' => GuildSummaryResource::collection($data['recently_searched']),
            'most_popular' => GuildSummaryResource::collection($data['most_popular']),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => 'present|nullable|string|max:64']);

        $rows = Guild::nameSearch((string) $request->query('q'))->get();

        return response()->json([
            'suggestions' => GuildSuggestionResource::collection($rows),
        ]);
    }

    public function discover(GuildService $service): JsonResponse
    {
        return response()->json($service->getDiscover());
    }
}
