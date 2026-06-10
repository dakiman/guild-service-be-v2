<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use Illuminate\Support\Facades\Http;

class BlizzardUserClient
{
    public function getUserInfo(string $region, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->connectTimeout(5)
            ->get("https://{$region}.battle.net/oauth/userinfo");

        $response->throw();

        return $response->json();
    }

    public function getUserCharacters(string $region, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->connectTimeout(5)
            ->withQueryParameters([
                'namespace' => "profile-{$region}",
                'locale' => 'en_GB',
            ])
            ->get("https://{$region}.api.blizzard.com/profile/user/wow");

        $response->throw();

        return $response->json();
    }
}
