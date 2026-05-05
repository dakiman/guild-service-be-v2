<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Exceptions\EntityNotFoundException;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\CharacterSuggestionResource;
use App\Http\Resources\CharacterSummaryResource;
use App\Models\Character;
use App\Services\CharacterService;
use App\Support\BlizzardIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CharacterController extends Controller
{
    public function show(string $region, string $realm, string $character, CharacterService $service, Request $request): JsonResponse
    {
        $realm = BlizzardIdentity::realm($realm);
        $character = BlizzardIdentity::name($character);

        try {
            $result = $service->getByIdentity($region, $realm, $character);
        } catch (EntityNotFoundException) {
            return response()->json(['message' => 'Character not found'], 404);
        }

        if ($result === null) {
            SyncCharacterData::dispatch($region, $realm, $character, SyncDepth::Standard);

            return response()->json(['message' => 'Character sync initiated'], 202)
                ->header('Retry-After', '5');
        }

        $relations = ['guild', 'dungeonRuns.memberEntries', 'pvpBrackets', 'professions.expansion', 'raidEncounterKills', 'titles.gameData', 'reputations.faction.expansion', 'mounts.gameData', 'toys'];
        if (config('blizzard.sync.pets_enabled')) {
            $relations[] = 'pets';
        }
        $result->load($relations);

        $response = (new CharacterResource($result))->response($request);

        if ($result->isStale()) {
            $response->header('X-Data-Staleness', 'stale');
        }

        if (in_array('never_synced', $response->getData(true)['meta']['freshness'] ?? [], true)) {
            $response->header('X-Sync-Status', 'syncing');
            $response->header('Retry-After', '5');
        }

        return $response;
    }

    public function popular(CharacterService $service): JsonResponse
    {
        $data = $service->getPopular();

        return response()->json([
            'recently_searched' => CharacterSummaryResource::collection($data['recently_searched']),
            'most_popular' => CharacterSummaryResource::collection($data['most_popular']),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => 'present|nullable|string|max:64']);

        $rows = Character::nameSearch((string) $request->query('q'))->get();

        return response()->json([
            'suggestions' => CharacterSuggestionResource::collection($rows),
        ]);
    }

    public function toggleRecruitment(Character $character, CharacterService $service): JsonResponse
    {
        Gate::authorize('toggleRecruitment', $character);

        $character = $service->toggleRecruitment($character);

        return response()->json(new CharacterResource($character));
    }
}
