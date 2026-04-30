<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use App\Models\GameDataFaction;
use App\Models\GameDataMount;
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
}
