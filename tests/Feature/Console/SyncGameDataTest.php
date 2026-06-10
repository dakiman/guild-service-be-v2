<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use App\Models\GameDataFaction;
use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMount;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataRaidInstance;
use App\Models\GameDataTitle;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncGameDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);

        // Achievements game-data sync is gated on this flag (off by default
        // in production due to ~40k row disk cost). Tests assert the catalog
        // is populated, so flip it on for the duration of the test class.
        config(['blizzard.sync.achievements_enabled' => true]);
    }

    public function test_sync_factions_upserts_known_factions_with_expansion_id(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [
                ['id' => 2570, 'name' => 'Council of Dornogal'],
                ['id' => 2510, 'name' => 'Valdrakken Accord'],
                ['id' => 99999, 'name' => 'Unknown future faction'],
            ],
        ]);
        $mock->method('getFaction')->willReturnCallback(function (int $id): array {
            return match ($id) {
                2570 => ['id' => 2570, 'name' => 'Council of Dornogal', 'category' => ['id' => 1245]],
                2510 => ['id' => 2510, 'name' => 'Valdrakken Accord', 'category' => ['id' => 1234]],
                99999 => ['id' => 99999, 'name' => 'Unknown future faction'],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions'])
            ->assertExitCode(0);

        $this->assertSame(3, GameDataFaction::count());

        $tww = GameDataFaction::find(2570);
        $this->assertNotNull($tww);
        $this->assertSame(1, $tww->expansion_id, 'TWW faction maps to expansion 1');

        $df = GameDataFaction::find(2510);
        $this->assertSame(2, $df->expansion_id, 'Dragonflight faction maps to expansion 2');

        $unknown = GameDataFaction::find(99999);
        $this->assertNull($unknown->expansion_id, 'Unknown faction has null expansion_id');
    }

    public function test_sync_factions_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [['id' => 2570, 'name' => 'Council of Dornogal']],
        ]);
        $mock->method('getFaction')->willReturn([
            'id' => 2570, 'name' => 'Council of Dornogal',
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions']);

        $this->assertSame(1, GameDataFaction::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_factions_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [
                ['id' => 2570, 'name' => 'A'],
                ['id' => 2510, 'name' => 'B'],
            ],
        ]);
        $mock->method('getFaction')->willReturnCallback(function (int $id): ?array {
            if ($id === 2570) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B'];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions'])
            ->assertExitCode(0);

        $this->assertNull(GameDataFaction::find(2570));
        $this->assertNotNull(GameDataFaction::find(2510), 'second faction still upserted');
    }

    public function test_sync_titles_upserts_gender_specific_strings(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getTitleIndex')->willReturn([
            'titles' => [
                ['id' => 414, 'name' => '{name}, the Bear'],
                ['id' => 100, 'name' => '{name}, the Hallowed'],
            ],
        ]);
        $mock->method('getTitle')->willReturnCallback(function (int $id): array {
            return match ($id) {
                414 => [
                    'id' => 414,
                    'name' => '{name}, the Bear',
                    'gender_name' => [
                        'male' => '{name}, Lord of the Bears',
                        'female' => '{name}, Lady of the Bears',
                    ],
                ],
                100 => [
                    'id' => 100,
                    'name' => '{name}, the Hallowed',
                ],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataTitle::count());

        $bear = GameDataTitle::find(414);
        $this->assertNotNull($bear);
        $this->assertSame('{name}, Lord of the Bears', $bear->name_male);
        $this->assertSame('{name}, Lady of the Bears', $bear->name_female);

        $hallowed = GameDataTitle::find(100);
        $this->assertSame(
            '{name}, the Hallowed',
            $hallowed->name_male,
            'name_male falls back to name when gender_name absent',
        );
        $this->assertSame('{name}, the Hallowed', $hallowed->name_female);
    }

    public function test_sync_titles_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getTitleIndex')->willReturn([
            'titles' => [['id' => 414, 'name' => '{name}, the Bear']],
        ]);
        $mock->method('getTitle')->willReturn([
            'id' => 414,
            'name' => '{name}, the Bear',
            'gender_name' => [
                'male' => '{name}, Lord of the Bears',
                'female' => '{name}, Lady of the Bears',
            ],
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles']);

        $this->assertSame(1, GameDataTitle::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_titles_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getTitleIndex')->willReturn([
            'titles' => [
                ['id' => 414, 'name' => 'A'],
                ['id' => 100, 'name' => 'B'],
            ],
        ]);
        $mock->method('getTitle')->willReturnCallback(function (int $id): ?array {
            if ($id === 414) {
                throw new \RuntimeException('simulated transient failure');
            }

            return [
                'id' => $id,
                'name' => 'B',
                'gender_name' => ['male' => 'B', 'female' => 'B'],
            ];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles'])
            ->assertExitCode(0);

        $this->assertNull(GameDataTitle::find(414));
        $this->assertNotNull(GameDataTitle::find(100), 'second title still upserted');
    }

    public function test_sync_mounts_upserts_full_detail(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMountIndex')->willReturn([
            'mounts' => [
                ['id' => 6, 'name' => 'Onyxian Drake'],
                ['id' => 219, 'name' => 'Tawny Wind Rider'],
            ],
        ]);
        $mock->method('getMount')->willReturnCallback(function (int $id): array {
            return match ($id) {
                6 => [
                    'id' => 6,
                    'name' => 'Onyxian Drake',
                    'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
                    'summon_spell' => ['id' => 69395],
                    'item' => ['id' => 49636],
                ],
                219 => [
                    'id' => 219,
                    'name' => 'Tawny Wind Rider',
                    'source' => ['type' => 'VENDOR'],
                    'summon_spell' => ['id' => 32243],
                ],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataMount::count());

        $onyxia = GameDataMount::find(6);
        $this->assertNotNull($onyxia);
        $this->assertSame('Onyxian Drake', $onyxia->name);
        $this->assertSame('Drop: Onyxia', $onyxia->source_text);
        $this->assertSame(69395, $onyxia->summon_spell_id);
        $this->assertSame(49636, $onyxia->item_id);

        $tawny = GameDataMount::find(219);
        $this->assertSame('Vendor', $tawny->source_text);
        $this->assertSame(32243, $tawny->summon_spell_id);
        $this->assertNull($tawny->item_id);
    }

    public function test_sync_mounts_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMountIndex')->willReturn([
            'mounts' => [['id' => 6, 'name' => 'Onyxian Drake']],
        ]);
        $mock->method('getMount')->willReturn([
            'id' => 6,
            'name' => 'Onyxian Drake',
            'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
            'summon_spell' => ['id' => 69395],
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts']);

        $this->assertSame(1, GameDataMount::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_mounts_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMountIndex')->willReturn([
            'mounts' => [
                ['id' => 6, 'name' => 'A'],
                ['id' => 219, 'name' => 'B'],
            ],
        ]);
        $mock->method('getMount')->willReturnCallback(function (int $id): ?array {
            if ($id === 6) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B'];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts'])
            ->assertExitCode(0);

        $this->assertNull(GameDataMount::find(6));
        $this->assertNotNull(GameDataMount::find(219));
    }

    public function test_sync_achievements_upserts_categories_then_achievements_with_correct_fk(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);

        $mock->method('getAchievementCategoryIndex')->willReturn([
            'categories' => [
                ['id' => 1, 'name' => 'General'],
                ['id' => 81, 'name' => 'Quests'],
            ],
        ]);
        $mock->method('getAchievementCategory')->willReturnCallback(function (int $id): array {
            return match ($id) {
                1 => ['id' => 1, 'name' => 'General', 'display_order' => 0],
                81 => ['id' => 81, 'name' => 'Quests', 'parent_category' => ['id' => 1], 'display_order' => 3],
            };
        });

        $mock->method('getAchievementIndex')->willReturn([
            'achievements' => [
                ['id' => 5, 'name' => 'A'],
                ['id' => 6, 'name' => 'B'],
            ],
        ]);
        $mock->method('getAchievement')->willReturnCallback(function (int $id): array {
            return match ($id) {
                5 => ['id' => 5, 'name' => 'First Quest', 'category' => ['id' => 81], 'points' => 10, 'is_account_wide' => false],
                6 => ['id' => 6, 'name' => 'Account Quest', 'category' => ['id' => 81], 'points' => 20, 'is_account_wide' => true],
            };
        });

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataAchievementCategory::count());
        $this->assertSame(2, GameDataAchievement::count());

        $quests = GameDataAchievementCategory::find(81);
        $this->assertSame(1, $quests->parent_id, 'sub-category links to parent');

        $first = GameDataAchievement::find(5);
        $this->assertSame(81, $first->category_id);
        $this->assertSame(10, $first->points);
        $this->assertFalse($first->is_account_wide);

        $second = GameDataAchievement::find(6);
        $this->assertTrue($second->is_account_wide);
    }

    public function test_sync_achievements_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getAchievementCategoryIndex')->willReturn([
            'categories' => [['id' => 1, 'name' => 'General']],
        ]);
        $mock->method('getAchievementCategory')->willReturn([
            'id' => 1, 'name' => 'General',
        ]);
        $mock->method('getAchievementIndex')->willReturn([
            'achievements' => [['id' => 5, 'name' => 'A']],
        ]);
        $mock->method('getAchievement')->willReturn([
            'id' => 5, 'name' => 'First', 'category' => ['id' => 1], 'points' => 5,
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements']);

        $this->assertSame(1, GameDataAchievementCategory::count());
        $this->assertSame(1, GameDataAchievement::count());
    }

    public function test_sync_achievements_continues_on_individual_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getAchievementCategoryIndex')->willReturn([
            'categories' => [['id' => 1, 'name' => 'General']],
        ]);
        $mock->method('getAchievementCategory')->willReturn([
            'id' => 1, 'name' => 'General',
        ]);
        $mock->method('getAchievementIndex')->willReturn([
            'achievements' => [
                ['id' => 5, 'name' => 'A'],
                ['id' => 6, 'name' => 'B'],
            ],
        ]);
        $mock->method('getAchievement')->willReturnCallback(function (int $id): array {
            if ($id === 5) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B', 'category' => ['id' => 1], 'points' => 1];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements'])
            ->assertExitCode(0);

        $this->assertNull(GameDataAchievement::find(5));
        $this->assertNotNull(GameDataAchievement::find(6), 'second achievement still upserted');
    }

    public function test_sync_achievements_chunks_inserts_for_large_payloads(): void
    {
        $achievementRows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $achievementRows[] = ['id' => $i, 'name' => "Achievement #{$i}"];
        }

        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getAchievementCategoryIndex')->willReturn(['categories' => []]);
        $mock->method('getAchievementIndex')->willReturn(['achievements' => $achievementRows]);
        $mock->method('getAchievement')->willReturnCallback(function (int $id): array {
            return ['id' => $id, 'name' => "Achievement #{$id}", 'points' => 0];
        });

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements'])
            ->assertExitCode(0);

        $this->assertSame(1200, GameDataAchievement::count());
    }

    public function test_sync_pve_upserts_raid_instance_with_encounters_and_media(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);

        $mock->method('getJournalInstanceIndex')->willReturn([
            'instances' => [['id' => 1296, 'name' => 'Liberation of Undermine']],
        ]);

        $mock->method('getJournalInstance')->willReturn([
            'id' => 1296,
            'name' => 'Liberation of Undermine',
            'category' => ['type' => 'RAID'],
            'expansion' => ['id' => 514],
            'order_index' => 5,
            'encounters' => [
                ['id' => 2902, 'name' => 'Vexie'],
                ['id' => 2917, 'name' => 'Cauldron of Carnage'],
            ],
        ]);

        $mock->method('getJournalInstanceMedia')->willReturn([
            'assets' => [['key' => 'tile', 'value' => 'https://example/lou.jpg']],
        ]);

        $mock->method('getJournalEncounter')->willReturnCallback(function (int $id): array {
            return match ($id) {
                2902 => ['id' => 2902, 'name' => 'Vexie', 'creature_display' => ['id' => 109501], 'instance' => ['id' => 1296], 'order_index' => 0],
                2917 => ['id' => 2917, 'name' => 'Cauldron of Carnage', 'creature_display' => ['id' => 109502], 'instance' => ['id' => 1296], 'order_index' => 1],
            };
        });

        $mock->method('getCreatureDisplayMedia')->willReturnCallback(function (int $id): array {
            return ['assets' => [['key' => 'zoom', 'value' => "https://example/cd-{$id}.jpg"]]];
        });

        // Mythic-keystone branch — minimal, returns no dungeons (covered separately below).
        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn(['id' => 14, 'dungeons' => []]);
        $mock->method('getMythicKeystoneDungeonIndex')->willReturn(['dungeons' => []]);

        // Affix branch — minimal, no affixes.
        $mock->method('getKeystoneAffixIndex')->willReturn(['affixes' => []]);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $instance = GameDataRaidInstance::find(1296);
        $this->assertNotNull($instance);
        $this->assertSame('Liberation of Undermine', $instance->name);
        $this->assertSame(1, $instance->expansion_id);
        $this->assertSame(5, $instance->display_order);
        $this->assertSame('https://example/lou.jpg', $instance->media_url);

        $this->assertSame(2, GameDataRaidEncounter::where('raid_instance_id', 1296)->count());

        $vexie = GameDataRaidEncounter::find(2902);
        $this->assertSame('Vexie', $vexie->name);
        $this->assertSame(0, $vexie->display_order);
        $this->assertSame(109501, $vexie->creature_display_id);
        $this->assertSame('https://example/cd-109501.jpg', $vexie->portrait_url);
    }

    public function test_sync_pve_upserts_mythic_keystone_dungeons_from_current_season(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);

        $mock->method('getJournalInstanceIndex')->willReturn(['instances' => []]);

        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn([
            'id' => 14,
            'dungeons' => [
                ['id' => 503, 'name' => 'Ara-Kara'],
                ['id' => 504, 'name' => 'City of Threads'],
            ],
        ]);
        $mock->method('getMythicKeystoneDungeon')->willReturnCallback(function (int $id): array {
            return match ($id) {
                503 => ['id' => 503, 'name' => 'Ara-Kara, City of Echoes'],
                504 => ['id' => 504, 'name' => 'City of Threads'],
            };
        });

        $mock->method('getKeystoneAffixIndex')->willReturn(['affixes' => []]);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataMythicKeystoneDungeon::count());
        $this->assertSame('Ara-Kara, City of Echoes', GameDataMythicKeystoneDungeon::find(503)->name);
        $this->assertSame('City of Threads', GameDataMythicKeystoneDungeon::find(504)->name);
    }

    public function test_sync_pve_upserts_keystone_affixes_with_icons(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getJournalInstanceIndex')->willReturn(['instances' => []]);
        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn(['id' => 14, 'dungeons' => []]);
        $mock->method('getMythicKeystoneDungeonIndex')->willReturn(['dungeons' => []]);

        $mock->method('getKeystoneAffixIndex')->willReturn([
            'affixes' => [
                ['id' => 9, 'name' => 'Tyrannical'],
                ['id' => 10, 'name' => 'Fortified'],
            ],
        ]);
        $mock->method('getKeystoneAffix')->willReturnCallback(function (int $id): array {
            return match ($id) {
                9 => ['id' => 9, 'name' => 'Tyrannical'],
                10 => ['id' => 10, 'name' => 'Fortified'],
            };
        });
        $mock->method('getKeystoneAffixMedia')->willReturnCallback(function (int $id): array {
            return ['assets' => [['key' => 'icon', 'value' => "https://example/affix-{$id}.jpg"]]];
        });

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataKeystoneAffix::count());
        $tyr = GameDataKeystoneAffix::find(9);
        $this->assertSame('Tyrannical', $tyr->name);
        $this->assertSame('https://example/affix-9.jpg', $tyr->icon_url);
    }

    public function test_sync_pve_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getJournalInstanceIndex')->willReturn([
            'instances' => [['id' => 1296, 'name' => 'LoU']],
        ]);
        $mock->method('getJournalInstance')->willReturn([
            'id' => 1296,
            'name' => 'Liberation of Undermine',
            'category' => ['type' => 'RAID'],
            'expansion' => ['id' => 514],
            'order_index' => 5,
            'encounters' => [['id' => 2902, 'name' => 'Vexie']],
        ]);
        $mock->method('getJournalInstanceMedia')->willReturn([
            'assets' => [['key' => 'tile', 'value' => 'https://example/lou.jpg']],
        ]);
        $mock->method('getJournalEncounter')->willReturn([
            'id' => 2902, 'name' => 'Vexie', 'instance' => ['id' => 1296], 'order_index' => 0,
        ]);
        $mock->method('getCreatureDisplayMedia')->willReturn(null);

        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn([
            'id' => 14,
            'dungeons' => [['id' => 503]],
        ]);
        $mock->method('getMythicKeystoneDungeon')->willReturn([
            'id' => 503, 'name' => 'Ara-Kara',
        ]);
        $mock->method('getKeystoneAffixIndex')->willReturn([
            'affixes' => [['id' => 9, 'name' => 'Tyrannical']],
        ]);
        $mock->method('getKeystoneAffix')->willReturn(['id' => 9, 'name' => 'Tyrannical']);
        $mock->method('getKeystoneAffixMedia')->willReturn(null);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve']);

        $this->assertSame(1, GameDataRaidInstance::count(), 'rerun should not duplicate raid rows');
        $this->assertSame(1, GameDataRaidEncounter::count(), 'rerun should not duplicate encounter rows');
        $this->assertSame(1, GameDataMythicKeystoneDungeon::count(), 'rerun should not duplicate dungeon rows');
        $this->assertSame(1, GameDataKeystoneAffix::count(), 'rerun should not duplicate affix rows');
    }

    public function test_sync_pve_continues_when_individual_id_throws(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getJournalInstanceIndex')->willReturn([
            'instances' => [
                ['id' => 1296],
                ['id' => 1273],
            ],
        ]);
        $mock->method('getJournalInstance')->willReturnCallback(function (int $id): array {
            if ($id === 1296) {
                throw new \RuntimeException('simulated transient failure');
            }

            return [
                'id' => $id,
                'name' => 'Other raid',
                'category' => ['type' => 'RAID'],
                'expansion' => ['id' => 514],
                'order_index' => 0,
                'encounters' => [],
            ];
        });
        $mock->method('getJournalInstanceMedia')->willReturn(null);

        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn(['id' => 14, 'dungeons' => []]);
        $mock->method('getMythicKeystoneDungeonIndex')->willReturn(['dungeons' => []]);
        $mock->method('getKeystoneAffixIndex')->willReturn(['affixes' => []]);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $this->assertNull(GameDataRaidInstance::find(1296));
        $this->assertNotNull(GameDataRaidInstance::find(1273), 'second instance still upserted');
    }
}
