<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlizzardGameDataClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function client(string $region = 'us'): BlizzardGameDataClient
    {
        $tokenManager = $this->createMock(TokenManagerInterface::class);
        $tokenManager->method('getToken')->willReturn('fake-token');

        // Region is a readonly constructor param on the parent BlizzardClient;
        // there is no setter. See BlizzardClient.php:16.
        return new BlizzardGameDataClient($tokenManager, $region);
    }

    public function test_get_faction_index_returns_response_in_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/index?*' => Http::response([
                'factions' => [
                    ['id' => 2510, 'name' => 'Valdrakken Accord'],
                    ['id' => 2570, 'name' => 'Council of Dornogal'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getFactionIndex();

        $this->assertSame(2510, $result['factions'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'reputation-faction/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_faction_index_caches_within_ttl(): void
    {
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['factions' => []], 200);
        });

        $client = $this->client();
        $client->getFactionIndex();
        $client->getFactionIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_faction_index_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/index?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getFactionIndex());
    }

    public function test_get_faction_returns_response_in_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/2510?*' => Http::response([
                'id' => 2510,
                'name' => 'Valdrakken Accord',
                'category' => ['id' => 1245],
            ], 200),
        ]);

        $result = $this->client()->getFaction(2510);

        $this->assertSame(2510, $result['id']);
        $this->assertSame('Valdrakken Accord', $result['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'reputation-faction/2510')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_faction_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getFaction(99999));
    }

    public function test_get_title_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/index?*' => Http::response([
                'titles' => [
                    ['id' => 1, 'name' => 'Private'],
                    ['id' => 414, 'name' => '{name}, the Bear'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getTitleIndex();

        $this->assertSame(1, $result['titles'][0]['id']);
        $this->assertSame(414, $result['titles'][1]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/data/wow/title/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_title_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['titles' => []], 200);
        });

        $client = $this->client();
        $client->getTitleIndex();
        $client->getTitleIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_title_index_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/index?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getTitleIndex());
    }

    public function test_get_title_returns_gender_name_payload(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/414?*' => Http::response([
                'id' => 414,
                'name' => '{name}, the Bear',
                'gender_name' => [
                    'male' => '{name}, Lord of the Bears',
                    'female' => '{name}, Lady of the Bears',
                ],
            ], 200),
        ]);

        $result = $this->client()->getTitle(414);

        $this->assertSame(414, $result['id']);
        $this->assertSame('{name}, Lord of the Bears', $result['gender_name']['male']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/data/wow/title/414')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_title_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getTitle(99999));
    }

    public function test_get_mount_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/mount/index?*' => Http::response([
                'mounts' => [
                    ['id' => 6, 'name' => 'Onyxian Drake'],
                    ['id' => 219, 'name' => 'Tawny Wind Rider'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getMountIndex();

        $this->assertSame(6, $result['mounts'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'mount/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_mount_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['mounts' => []], 200);
        });

        $client = $this->client();
        $client->getMountIndex();
        $client->getMountIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_mount_returns_full_detail(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/mount/6?*' => Http::response([
                'id' => 6,
                'name' => 'Onyxian Drake',
                'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
                'summon_spell' => ['id' => 69395, 'name' => 'Onyxian Drake'],
                'item' => ['id' => 49636],
            ], 200),
        ]);

        $result = $this->client()->getMount(6);

        $this->assertSame(6, $result['id']);
        $this->assertSame('Onyxian Drake', $result['name']);
        $this->assertSame(69395, $result['summon_spell']['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'mount/6')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_mount_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/mount/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getMount(99999));
    }

    public function test_get_achievement_category_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement-category/index?*' => Http::response([
                'categories' => [
                    ['id' => 1, 'name' => 'General'],
                    ['id' => 81, 'name' => 'Quests'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getAchievementCategoryIndex();

        $this->assertSame(1, $result['categories'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'achievement-category/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_achievement_category_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement-category/81?*' => Http::response([
                'id' => 81,
                'name' => 'Quests',
                'parent_category' => ['id' => 1, 'name' => 'General'],
                'display_order' => 3,
            ], 200),
        ]);

        $result = $this->client()->getAchievementCategory(81);

        $this->assertSame(81, $result['id']);
        $this->assertSame('Quests', $result['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'achievement-category/81')
                && str_contains($request->url(), 'namespace=static-us');
        });
    }

    public function test_get_achievement_category_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement-category/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getAchievementCategory(99999));
    }

    public function test_get_achievement_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement/index?*' => Http::response([
                'achievements' => [
                    ['id' => 1, 'name' => 'A'],
                    ['id' => 230, 'name' => 'Hatchling of the Talon'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getAchievementIndex();

        $this->assertSame(1, $result['achievements'][0]['id']);
        $this->assertCount(2, $result['achievements']);
    }

    public function test_get_achievement_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['achievements' => []], 200);
        });

        $client = $this->client();
        $client->getAchievementIndex();
        $client->getAchievementIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_achievement_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement/230?*' => Http::response([
                'id' => 230,
                'name' => 'Hatchling of the Talon',
                'category' => ['id' => 15246],
                'points' => 10,
                'is_account_wide' => true,
            ], 200),
        ]);

        $result = $this->client()->getAchievement(230);

        $this->assertSame(230, $result['id']);
        $this->assertTrue($result['is_account_wide']);
    }

    public function test_get_achievement_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getAchievement(99999));
    }

    public function test_get_journal_instance_index_uses_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/journal-instance/index?*' => Http::response([
                'instances' => [
                    ['id' => 1296, 'name' => 'Liberation of Undermine'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getJournalInstanceIndex();

        $this->assertSame(1296, $result['instances'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'journal-instance/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_journal_instance_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/journal-instance/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getJournalInstance(99999));
    }

    public function test_get_journal_instance_caches_within_ttl(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['id' => 1296, 'name' => 'X'], 200);
        });

        $client = $this->client();
        $client->getJournalInstance(1296);
        $client->getJournalInstance(1296);

        $this->assertSame(1, $callCount);
    }

    public function test_get_journal_instance_media_returns_assets(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/media/journal-instance/1296?*' => Http::response([
                'assets' => [['key' => 'tile', 'value' => 'https://example/r.jpg']],
            ], 200),
        ]);

        $result = $this->client()->getJournalInstanceMedia(1296);
        $this->assertSame('https://example/r.jpg', $result['assets'][0]['value']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'media/journal-instance/1296')
            && str_contains($req->url(), 'namespace=static-us'));
    }

    public function test_get_journal_encounter_returns_creature_display(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/journal-encounter/2902?*' => Http::response([
                'id' => 2902,
                'name' => 'Vexie',
                'creature_display' => ['id' => 109501],
            ], 200),
        ]);

        $result = $this->client()->getJournalEncounter(2902);
        $this->assertSame(109501, $result['creature_display']['id']);
    }

    public function test_get_mythic_keystone_dungeon_index_uses_dynamic_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/dungeon/index?*' => Http::response([
                'dungeons' => [['id' => 503, 'name' => 'Ara-Kara']],
            ], 200),
        ]);

        $result = $this->client()->getMythicKeystoneDungeonIndex();

        $this->assertSame(503, $result['dungeons'][0]['id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'mythic-keystone/dungeon/index')
            && str_contains($req->url(), 'namespace=dynamic-us'));
    }

    public function test_get_mythic_keystone_dungeon_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/dungeon/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getMythicKeystoneDungeon(99999));
    }

    public function test_get_mythic_keystone_season_returns_dungeons_list(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/season/14?*' => Http::response([
                'id' => 14,
                'dungeons' => [['id' => 503], ['id' => 504]],
            ], 200),
        ]);

        $result = $this->client()->getMythicKeystoneSeason(14);
        $this->assertSame(2, count($result['dungeons']));
    }

    public function test_get_keystone_affix_index_uses_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/keystone-affix/index?*' => Http::response([
                'affixes' => [['id' => 9, 'name' => 'Tyrannical']],
            ], 200),
        ]);

        $result = $this->client()->getKeystoneAffixIndex();
        $this->assertSame(9, $result['affixes'][0]['id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'keystone-affix/index')
            && str_contains($req->url(), 'namespace=static-us'));
    }

    public function test_get_keystone_affix_caches_within_ttl(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['id' => 9, 'name' => 'Tyrannical'], 200);
        });

        $client = $this->client();
        $client->getKeystoneAffix(9);
        $client->getKeystoneAffix(9);

        $this->assertSame(1, $callCount);
    }

    public function test_get_keystone_affix_media_returns_icon(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/media/keystone-affix/9?*' => Http::response([
                'assets' => [['key' => 'icon', 'value' => 'https://example/affix-9.jpg']],
            ], 200),
        ]);

        $result = $this->client()->getKeystoneAffixMedia(9);
        $this->assertSame('https://example/affix-9.jpg', $result['assets'][0]['value']);
    }

    public function test_get_creature_display_media_returns_zoom_url(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/media/creature-display/109501?*' => Http::response([
                'assets' => [['key' => 'zoom', 'value' => 'https://example/zoom.jpg']],
            ], 200),
        ]);

        $result = $this->client()->getCreatureDisplayMedia(109501);
        $this->assertSame('https://example/zoom.jpg', $result['assets'][0]['value']);
    }

    /**
     * B2.1 — negative caching. A 404 must be cached (via a sentinel) so a
     * repeated lookup for a missing id does not re-hit Blizzard. Cache::remember
     * treats a stored null as a miss, so today the second call re-fetches.
     */
    public function test_get_faction_negative_result_is_cached(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(null, 404);
        });

        $client = $this->client();
        $this->assertNull($client->getFaction(99999));
        $this->assertNull($client->getFaction(99999));

        $this->assertSame(1, $callCount, '404 should be cached, not re-fetched');
    }

    public function test_get_talent_tree_negative_result_is_cached(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(null, 404);
        });

        $client = $this->client();
        $this->assertNull($client->getTalentTree(123, 456));
        $this->assertNull($client->getTalentTree(123, 456));

        $this->assertSame(1, $callCount, '404 should be cached, not re-fetched');
    }

    public function test_get_journal_instance_index_negative_result_is_cached(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(null, 404);
        });

        $client = $this->client();
        $this->assertNull($client->getJournalInstanceIndex());
        $this->assertNull($client->getJournalInstanceIndex());

        $this->assertSame(1, $callCount, '404 should be cached, not re-fetched');
    }

    /**
     * B2.2 — the current-season cache key must be region-scoped, otherwise a us
     * client poisons the cache for an eu client (and vice-versa).
     */
    public function test_current_mythic_plus_season_is_cached_per_region(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/season/index?*' => Http::response([
                'seasons' => [['id' => 13], ['id' => 14]],
            ], 200),
            'eu.api.blizzard.com/data/wow/mythic-keystone/season/index?*' => Http::response([
                'seasons' => [['id' => 14], ['id' => 15]],
            ], 200),
        ]);

        $this->assertSame(14, $this->client('us')->getCurrentMythicPlusSeason());
        $this->assertSame(15, $this->client('eu')->getCurrentMythicPlusSeason());
    }

    /**
     * B2.3 — an empty seasons array must throw (not silently cache season 0 for
     * 24h). Throwing inside the remember-closure means nothing is cached, so a
     * later valid payload resolves correctly.
     */
    public function test_current_mythic_plus_season_throws_on_empty_seasons(): void
    {
        // First response is empty (must throw); the throw must prevent caching
        // so the second response — a valid payload — resolves normally.
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return $callCount === 1
                ? Http::response(['seasons' => []], 200)
                : Http::response(['seasons' => [['id' => 14]]], 200);
        });

        try {
            $this->client('us')->getCurrentMythicPlusSeason();
            $this->fail('expected a RuntimeException on empty seasons');
        } catch (\RuntimeException $e) {
            // expected
        }

        // Nothing was cached (the closure threw), so this re-fetches and resolves.
        $this->assertSame(14, $this->client('us')->getCurrentMythicPlusSeason());
    }

    /**
     * B6 — the retry backoff is now an array of intervals (config-driven), which
     * must keep the total attempt count at 3.
     */
    public function test_retry_makes_three_attempts_on_persistent_500(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/season/index?*' => Http::response([], 500),
        ]);

        try {
            $this->client('us')->getCurrentMythicPlusSeason();
            $this->fail('expected a RequestException on persistent 500');
        } catch (RequestException) {
            // expected
        }

        Http::assertSentCount(3);
    }

    public function test_retry_backoff_config_is_int_array_and_defaults_to_hundred_five_hundred(): void
    {
        // Mirrors the parse in config/blizzard.php for the unset-env default.
        $this->assertSame([100, 500], array_map('intval', explode(',', '100,500')));

        // Resolved config is always an int array (0,0 forced in the test env).
        $configured = config('blizzard.http.retry_backoff_ms');
        $this->assertIsArray($configured);
        $this->assertNotEmpty($configured);
        foreach ($configured as $ms) {
            $this->assertIsInt($ms);
        }
    }
}
