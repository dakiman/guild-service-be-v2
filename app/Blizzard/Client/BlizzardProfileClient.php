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

    /**
     * @return array{base: ?array, season: ?array}
     */
    public function getCharacterMythicPlusPool(string $realm, string $name, int $season): array
    {
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
