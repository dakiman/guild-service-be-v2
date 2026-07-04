<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BlizzardGameDataClient extends BlizzardClient
{
    /**
     * Stored in place of null so Cache::remember treats a 404 as a real hit
     * (it otherwise re-evaluates the closure whenever a cached value is null),
     * giving us negative caching for missing ids.
     */
    private const NULL_SENTINEL = '__none__';

    protected function namespace(): string
    {
        return "dynamic-{$this->region}";
    }

    /**
     * Cache::remember wrapper for ?array getters whose closure returns null on
     * 404. Persists a sentinel for null so the 404 is cached instead of
     * re-fetched on every call.
     */
    private function rememberNullable(string $key, int $ttl, \Closure $fn): ?array
    {
        $value = Cache::remember($key, $ttl, fn () => $fn() ?? self::NULL_SENTINEL);

        return $value === self::NULL_SENTINEL ? null : $value;
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

        return (int) Cache::remember("blizzard:mythic-plus:current-season:{$this->region}", 86400, function () {
            $response = $this->request()
                ->get('/data/wow/mythic-keystone/season/index');

            $response->throw();

            $data = $response->json();
            $seasons = $data['seasons'] ?? [];

            if ($seasons === []) {
                throw new \RuntimeException('Blizzard season index returned no seasons');
            }

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

        return $this->rememberNullable($cacheKey, $ttl, function () use ($treeId, $specId): ?array {
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

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
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

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
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

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
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

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
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

    /**
     * Fetch the mount index from /data/wow/mount/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * Lives in the static-{region} namespace (patch-pinned reference data),
     * not dynamic-, so we bypass request() and call Http directly — same
     * convention as getTalentTree() / getFactionIndex() above.
     *
     * Cached aggressively because the index only changes on patches.
     */
    public function getMountIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:mount-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mount/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single mount by ID from /data/wow/mount/{id}.
     * Returns the raw response array (description, source, summon_spell, item, etc.).
     *
     * Cached for the same TTL as the index.
     */
    public function getMount(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:mount:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mount/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch the achievement-category index from
     * /data/wow/achievement-category/index. Returns the raw response array;
     * mapper extracts IDs.
     *
     * Lives in the static-{region} namespace (patch-pinned reference data) —
     * bypasses request() like getTalentTree()/getFactionIndex().
     */
    public function getAchievementCategoryIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:achievement-category-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement-category/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single achievement category by ID from
     * /data/wow/achievement-category/{id}.
     */
    public function getAchievementCategory(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:achievement-category:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement-category/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch the achievement index from /data/wow/achievement/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * Lives in the static-{region} namespace.
     */
    public function getAchievementIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:achievement-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single achievement by ID from /data/wow/achievement/{id}.
     */
    public function getAchievement(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:achievement:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch the journal-instance index from /data/wow/journal-instance/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * static-{region} namespace, 7-day cache — same precedent as
     * getFactionIndex() / getTalentTree().
     */
    public function getJournalInstanceIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:journal-instance-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/journal-instance/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single journal-instance by ID. Carries `expansion`, `order_index`
     * and `encounters: [{id, name}, ...]` — the encounter list is the boss
     * roster for the raid.
     */
    public function getJournalInstance(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:journal-instance:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/journal-instance/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/media/journal-instance/{id} — carries the raid
     * background image URL inside `assets[].value`.
     */
    public function getJournalInstanceMedia(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:journal-instance-media:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/media/journal-instance/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single journal-encounter by ID. Carries `creature_display.id`
     * (sometimes nested under `creature_displays[]`) — the FE uses this for
     * the boss portrait via the media/creature-display endpoint.
     */
    public function getJournalEncounter(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:journal-encounter:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/journal-encounter/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/media/creature-display/{id} — boss portrait URL.
     */
    public function getCreatureDisplayMedia(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:creature-display-media:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/media/creature-display/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/mythic-keystone/dungeon/index — list of all
     * mythic-keystone dungeons (current expansion's pool only; older
     * expansions' dungeons drop out of the index when their seasons retire).
     */
    public function getMythicKeystoneDungeonIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:mk-dungeon-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mythic-keystone/dungeon/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/mythic-keystone/dungeon/{id} — name, map id,
     * keystone-upgrades. Note: this endpoint is in the **dynamic** namespace,
     * unlike the journal-instance endpoints, because mythic-keystone dungeons
     * rotate per season.
     */
    public function getMythicKeystoneDungeon(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:mk-dungeon:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mythic-keystone/dungeon/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/mythic-keystone/season/{id} — gives the season's
     * `dungeons: [{id, ...}]` list, used by the sync command to know which
     * dungeons belong to the current season.
     */
    public function getMythicKeystoneSeason(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:mk-season:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mythic-keystone/season/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/realm/index — per-region realm list (the same names a
     * player sees in WoW's character-select). Lives in the dynamic-{region}
     * namespace because realm population/status is dynamic data.
     *
     * Index entries carry only `{key, name, id, slug}` — no is_tournament /
     * type / category. Filtering tournament/PTR realms would require per-realm
     * fan-out (~250 calls/region); not worth it for the autocomplete use case.
     */
    public function getRealmIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:realm-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/realm/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/keystone-affix/index — the universe of keystone affixes.
     */
    public function getKeystoneAffixIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:keystone-affix-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/keystone-affix/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/keystone-affix/{id} — name + description.
     */
    public function getKeystoneAffix(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:keystone-affix:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/keystone-affix/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/media/keystone-affix/{id} — affix icon URL.
     */
    public function getKeystoneAffixMedia(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:keystone-affix-media:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/media/keystone-affix/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/playable-specialization/index in the static-{region}
     * namespace. Returns {character_specializations: [{id, key:{href}, name}], ...}.
     * Cached aggressively because the index only changes on patches.
     */
    public function getPlayableSpecializationIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:playable-specialization-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/playable-specialization/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/playable-specialization/{specId}. Returns the spec detail,
     * which includes `talent_tree.id` — the value we feed back into getTalentTree().
     * Lives in static-{region} namespace.
     */
    public function getPlayableSpecialization(int $specId): ?array
    {
        $cacheKey = "blizzard:game-data:playable-specialization:{$this->region}:{$specId}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return $this->rememberNullable($cacheKey, $ttl, function () use ($specId): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/playable-specialization/{$specId}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
}
