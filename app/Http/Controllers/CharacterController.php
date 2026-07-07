<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Exceptions\EntityNotFoundException;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\CharacterSuggestionResource;
use App\Models\Character;
use App\Services\CharacterService;
use App\Support\BlizzardIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

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

            $queueDepth = (int) Queue::size('blizzard-user-sync');

            return response()->json([
                'message' => 'Character sync initiated',
                'queue_depth' => $queueDepth,
            ], 202)->header('Retry-After', $queueDepth > 100 ? '30' : '10');
        }

        // Basic-tier (sub-endgame) characters have no slice rows — skip the
        // slice eager-loads; whenLoaded omits those keys from the payload.
        $relations = ['guild'];
        if ($result->isEndgame()) {
            $relations = ['guild', 'dungeonRuns.memberEntries', 'pvpBrackets', 'professions.expansion', 'raidEncounterKills'];
            if (config('blizzard.sync.mounts_enabled')) {
                $relations[] = 'mounts.gameData';
            }
            if (config('blizzard.sync.pets_enabled')) {
                $relations[] = 'pets';
            }
            if (config('blizzard.sync.toys_enabled')) {
                $relations[] = 'toys';
            }
        }
        $result->load($relations);

        $response = (new CharacterResource($result))->response($request);

        if ($result->isStale()) {
            $response->header('X-Data-Staleness', 'stale');
        }

        if ($result->isNeverSynced()) {
            $response->header('X-Sync-Status', 'syncing');
            $response->header('Retry-After', '30');
        }

        return $response;
    }

    public function popular(CharacterService $service): JsonResponse
    {
        return response()->json($service->getPopular());
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
