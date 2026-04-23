<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class BlizzardProfileClient extends BlizzardClient
{
    protected function namespace(): string
    {
        return "profile-{$this->region}";
    }

    protected function timeout(): int
    {
        return (int) config('blizzard.timeouts.character_profile', 15);
    }

    public function getCharacterData(string $realm, string $name): array
    {
        $basePath = "/profile/wow/character/{$realm}/{$name}";
        $token = $this->tokenManager->getToken($this->region);
        $namespace = $this->namespace();
        $baseUrl = $this->baseUrl();
        $timeout = (int) config('blizzard.timeouts.character_pool', 20);

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('basic')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get($basePath),

            $pool->as('media')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/character-media"),

            $pool->as('equipment')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/equipment"),

            $pool->as('specializations')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/specializations"),
        ]);

        return [
            'basic' => $responses['basic']->json(),
            'media' => $responses['media']->json(),
            'equipment' => $responses['equipment']->json(),
            'specializations' => $responses['specializations']->json(),
        ];
    }

    public function getCharacterMythicPlus(string $realm, string $name, int $season): array
    {
        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/mythic-keystone-profile/season/{$season}");

        $response->throw();

        return $response->json();
    }

    public function getGuildData(string $realm, string $guild): array
    {
        $response = $this->request()
            ->get("/data/wow/guild/{$realm}/{$guild}");

        $response->throw();

        return $response->json();
    }

    public function getGuildRoster(string $realm, string $guild): array
    {
        $response = $this->request()
            ->get("/data/wow/guild/{$realm}/{$guild}/roster");

        $response->throw();

        return $response->json();
    }
}
