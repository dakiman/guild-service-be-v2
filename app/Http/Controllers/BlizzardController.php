<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Jobs\SyncUserCharacters;
use App\Http\Requests\BlizzardOAuthRequest;
use Illuminate\Http\JsonResponse;

class BlizzardController extends Controller
{
    public function handleCode(BlizzardOAuthRequest $request, string $region): JsonResponse
    {
        /** @var BlizzardAuthClient $authClient */
        $authClient = app(BlizzardAuthClient::class);

        $tokenResponse = $authClient->getOauthAccessToken(
            $region,
            $request->validated('code'),
            $request->validated('redirectUri'),
        );

        $user = $request->user();
        $user->update(['bnet_region' => $region]);

        SyncUserCharacters::dispatch($user, $region, $tokenResponse->access_token);

        return response()->json(['message' => 'Battle.net sync initiated'], 202)
            ->header('Retry-After', '5');
    }
}
