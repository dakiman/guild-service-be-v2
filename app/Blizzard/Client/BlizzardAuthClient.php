<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use Illuminate\Support\Facades\Http;

class BlizzardAuthClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function getToken(string $region = 'eu'): object
    {
        $response = Http::timeout(10)
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post("https://{$region}.battle.net/oauth/token", [
                'grant_type' => 'client_credentials',
            ]);

        $response->throw();

        return $response->object();
    }

    public function getOauthAccessToken(string $region, string $code, string $redirectUri): object
    {
        $response = Http::timeout(10)
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post("https://{$region}.battle.net/oauth/token", [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        $response->throw();

        return $response->object();
    }
}
