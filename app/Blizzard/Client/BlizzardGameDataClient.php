<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BlizzardGameDataClient extends BlizzardClient
{
    protected function namespace(): string
    {
        return "dynamic-{$this->region}";
    }

    protected function timeout(): int
    {
        return 30;
    }

    public function getCurrentMythicPlusSeason(): int
    {
        $override = config('blizzard.mythic_plus.season_override');

        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return (int) Cache::remember('blizzard:mythic-plus:current-season', 86400, function () {
            $response = $this->request()
                ->get('/data/wow/mythic-keystone/season/index');

            $response->throw();

            $data = $response->json();
            $seasons = $data['seasons'] ?? [];
            $lastSeason = end($seasons);

            return (int) $lastSeason['id'];
        });
    }

    /**
     * Fetch a class talent tree scoped to a specialization. The response includes
     * class_talent_nodes, spec_talent_nodes, and hero_talent_trees — each node carries
     * a ranks[] array (length = max rank) with per-rank spell tooltips. Endpoint lives
     * in the static-{region} namespace, not dynamic-, so we bypass request() and call
     * Http directly here.
     *
     * Returns null on 404. Cached aggressively because trees only change on patches.
     */
    public function getTalentTree(int $treeId, int $specId): ?array
    {
        $cacheKey = "blizzard:talent-tree:{$this->region}:{$treeId}:{$specId}";
        $ttl = (int) config('blizzard.talent_tree_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($treeId, $specId): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/talent-tree/{$treeId}/playable-specialization/{$specId}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch the reputation-faction index from /data/wow/reputation-faction/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * Lives in the static-{region} namespace (patch-pinned reference data),
     * not dynamic-, so we bypass request() and call Http directly — same
     * convention as getTalentTree() above.
     *
     * Cached aggressively because the index only changes on patches.
     */
    public function getFactionIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:faction-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/reputation-faction/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single reputation-faction by ID from
     * /data/wow/reputation-faction/{id}. Returns the raw response array.
     *
     * Cached for the same TTL as the index.
     */
    public function getFaction(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:faction:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/reputation-faction/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch the title index from /data/wow/title/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * static-{region} namespace, 7-day cache — same precedent as
     * getFactionIndex() and getTalentTree().
     */
    public function getTitleIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:title-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/title/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single title by ID from /data/wow/title/{id}.
     * Response carries `gender_name: { male, female }` for gendered titles.
     */
    public function getTitle(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:title:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/title/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
}
