<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Contracts\TokenManagerInterface;
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

    private function client(): BlizzardGameDataClient
    {
        $tokenManager = $this->createMock(TokenManagerInterface::class);
        $tokenManager->method('getToken')->willReturn('fake-token');

        // Region is a readonly constructor param on the parent BlizzardClient;
        // there is no setter. See BlizzardClient.php:16.
        return new BlizzardGameDataClient($tokenManager, 'us');
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
}
