<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Jobs\SyncUserCharacters;
use App\Http\Requests\BlizzardOAuthRequest;
use App\Http\Requests\BlizzardOAuthStateRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BlizzardController extends Controller
{
    public function state(BlizzardOAuthStateRequest $request, string $region): JsonResponse
    {
        $state = Str::random(64);
        $ttl = (int) config('blizzard.oauth.state_ttl', 600);
        $user = $request->user();

        Cache::put(
            "blizzard:oauth-state:{$user->id}:{$region}:{$state}",
            ['redirectUri' => $request->validated('redirectUri')],
            $ttl
        );

        return response()->json([
            'state' => $state,
            'expires_in' => $ttl,
        ]);
    }

    public function handleCode(BlizzardOAuthRequest $request, string $region): JsonResponse
    {
        $user = $request->user();
        $state = $request->validated('state');
        $cacheKey = "blizzard:oauth-state:{$user->id}:{$region}:{$state}";
        // Atomic single-use: pull = get + forget. Replays must fail.
        $statePayload = Cache::pull($cacheKey);

        if (! is_array($statePayload)
            || ($statePayload['redirectUri'] ?? null) !== $request->validated('redirectUri')) {
            return response()->json(['message' => 'Invalid OAuth state.'], 422);
        }

        /** @var BlizzardAuthClient $authClient */
        $authClient = app(BlizzardAuthClient::class);

        try {
            $tokenResponse = $authClient->getOauthAccessToken(
                $region,
                $request->validated('code'),
                $request->validated('redirectUri'),
            );
        } catch (RequestException) {
            // Invalid/expired authorization code — a client error, not a 500. (P1.11)
            return response()->json(['message' => 'Could not exchange the Battle.net authorization code.'], 422);
        }

        $user->update(['bnet_region' => $region, 'bnet_sync_status' => 'syncing']);

        SyncUserCharacters::dispatch($user, $region, $tokenResponse->access_token);

        return response()->json(['message' => 'Battle.net sync initiated'], 202)
            ->header('Retry-After', '5');
    }
}
