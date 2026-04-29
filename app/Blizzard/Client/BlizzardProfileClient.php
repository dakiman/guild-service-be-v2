<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Support\BlizzardIdentity;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
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
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

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

        $basic = $responses['basic'];
        if ($basic->status() === 404) {
            throw new BlizzardNotFoundException("character not found: {$this->region}/{$realm}/{$name}");
        }
        $basic->throw();

        return [
            'basic' => $basic->json(),
            'media' => $responses['media']->successful() ? $responses['media']->json() : null,
            'equipment' => $responses['equipment']->successful() ? $responses['equipment']->json() : null,
            'specializations' => $responses['specializations']->successful() ? $responses['specializations']->json() : null,
        ];
    }

    /**
     * @return array{base: ?array, season: ?array}
     */
    public function getCharacterMythicPlusPool(string $realm, string $name, int $season): array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $basePath = "/profile/wow/character/{$realm}/{$name}";
        $token = $this->tokenManager->getToken($this->region);
        $namespace = $this->namespace();
        $baseUrl = $this->baseUrl();
        $timeout = (int) config('blizzard.timeouts.character_pool', 20);

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('base')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/mythic-keystone-profile"),

            $pool->as('season')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/mythic-keystone-profile/season/{$season}"),
        ]);

        return [
            'base' => $responses['base']->successful() ? $responses['base']->json() : null,
            'season' => $responses['season']->successful() ? $responses['season']->json() : null,
        ];
    }

    public function getCharacterPvpSummary(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/pvp-summary");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    /**
     * Chunked fan-out — at most 3 parallel requests per chunk so a single
     * Full-sync job can't burst past the per-second rate-limit budget under
     * Horizon's max concurrency. Returns [slug => decoded_body | null].
     *
     * @param  string[]  $slugs
     * @return array<string, ?array>
     */
    public function getCharacterPvpBracketsChunked(string $realm, string $name, array $slugs, int $chunkSize = 3): array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        if ($slugs === []) {
            return [];
        }

        $basePath = "/profile/wow/character/{$realm}/{$name}/pvp-bracket";
        $token = $this->tokenManager->getToken($this->region);
        $namespace = $this->namespace();
        $baseUrl = $this->baseUrl();
        $timeout = (int) config('blizzard.timeouts.character_pool', 20);
        $out = [];

        foreach (array_chunk($slugs, $chunkSize) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $basePath, $token, $namespace, $baseUrl, $timeout) {
                $reqs = [];
                foreach ($chunk as $slug) {
                    $reqs[] = $pool->as($slug)
                        ->withToken($token)
                        ->baseUrl($baseUrl)
                        ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                        ->timeout($timeout)
                        ->connectTimeout(5)
                        ->get("{$basePath}/{$slug}");
                }

                return $reqs;
            });

            foreach ($chunk as $slug) {
                $r = $responses[$slug] ?? null;
                $out[$slug] = ($r && $r->successful()) ? $r->json() : null;
            }
        }

        return $out;
    }

    public function getCharacterProfessions(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/professions");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    public function getCharacterRaidEncounters(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/encounters/raids");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    public function getCharacterStats(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/character-stats");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    public function getCharacterTitles(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/titles");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    public function getGuildData(string $realm, string $guild): array
    {
        $realm = BlizzardIdentity::realm($realm);
        $guild = BlizzardIdentity::realm($guild);

        try {
            $response = $this->request()
                ->get("/data/wow/guild/{$realm}/{$guild}");
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                throw new BlizzardNotFoundException("guild not found: {$this->region}/{$realm}/{$guild}", previous: $e);
            }

            throw $e;
        }

        return $response->json();
    }

    public function getGuildRoster(string $realm, string $guild): array
    {
        $realm = BlizzardIdentity::realm($realm);
        $guild = BlizzardIdentity::realm($guild);

        try {
            $response = $this->request()
                ->get("/data/wow/guild/{$realm}/{$guild}/roster");
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                throw new BlizzardNotFoundException("guild roster not found: {$this->region}/{$realm}/{$guild}", previous: $e);
            }

            throw $e;
        }

        return $response->json();
    }
}
