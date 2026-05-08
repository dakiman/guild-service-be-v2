<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\CharacterAchievement;
use App\Models\CharacterMount;
use App\Models\CharacterPet;
use App\Models\CharacterToy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSliceGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TokenManagerInterface::class, fn () => new class implements TokenManagerInterface
        {
            public function getToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }

            public function refreshToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }
        });

        Config::set('blizzard.sync.mythic_plus_enabled', false);
        Config::set('blizzard.sync.pvp_enabled', false);
        Config::set('blizzard.sync.professions_enabled', false);
        Config::set('blizzard.sync.raids_enabled', false);
        Config::set('blizzard.sync.teammate_crawl_enabled', false);
        Config::set('blizzard.sync.mounts_enabled', false);
        Config::set('blizzard.sync.toys_enabled', false);
        Config::set('blizzard.sync.achievements_enabled', false);
        Config::set('blizzard.sync.pets_enabled', false);
    }

    // -------------------------------------------------------------------------
    // Achievements slice
    // -------------------------------------------------------------------------

    public function test_sync_achievements_skips_http_and_db_write_when_flag_off(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);

        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'gatetest', SyncDepth::Full);

        $this->assertSame(0, CharacterAchievement::count(), 'no achievement rows expected when flag is off');
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/achievements'));
    }

    // -------------------------------------------------------------------------
    // Mounts slice
    // -------------------------------------------------------------------------

    public function test_sync_collections_skips_mounts_when_flag_off(): void
    {
        Config::set('blizzard.sync.mounts_enabled', false);
        Config::set('blizzard.sync.toys_enabled', true);

        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/mounttest/collections/mounts*' => Http::response([
                'mounts' => [
                    ['mount' => ['id' => 1234, 'name' => 'Test Mount'], 'is_useable' => true],
                ],
            ], 200),
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/mounttest/collections/pets*' => Http::response(['pets' => []], 200),
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/mounttest/collections/toys*' => Http::response([
                'toys' => [
                    ['toy' => ['id' => 10, 'name' => 'Test Toy']],
                ],
            ], 200),
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'mounttest', SyncDepth::Full);

        $this->assertSame(0, CharacterMount::count(), 'no mount rows expected when mounts flag is off');
        $this->assertGreaterThan(0, CharacterToy::count(), 'toys should be written when toys flag is on');
    }

    // -------------------------------------------------------------------------
    // Toys slice
    // -------------------------------------------------------------------------

    public function test_sync_collections_skips_toys_when_flag_off(): void
    {
        Config::set('blizzard.sync.toys_enabled', false);
        Config::set('blizzard.sync.mounts_enabled', true);

        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/toytest/collections/mounts*' => Http::response([
                'mounts' => [
                    ['mount' => ['id' => 1234, 'name' => 'Test Mount'], 'is_useable' => true],
                ],
            ], 200),
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/toytest/collections/pets*' => Http::response(['pets' => []], 200),
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/toytest/collections/toys*' => Http::response([
                'toys' => [
                    ['toy' => ['id' => 10, 'name' => 'Test Toy']],
                ],
            ], 200),
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'toytest', SyncDepth::Full);

        $this->assertSame(0, CharacterToy::count(), 'no toy rows expected when toys flag is off');
        $this->assertGreaterThan(0, CharacterMount::count(), 'mounts should be written when mounts flag is on');
    }

    // -------------------------------------------------------------------------
    // Pets slice
    // -------------------------------------------------------------------------

    public function test_sync_collections_writes_mounts_but_not_pets_when_pets_flag_off(): void
    {
        Config::set('blizzard.sync.pets_enabled', false);
        Config::set('blizzard.sync.mounts_enabled', true);

        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/pettest/collections/mounts*' => Http::response([
                'mounts' => [
                    ['mount' => ['id' => 1234, 'name' => 'Test Mount'], 'is_useable' => true],
                ],
            ], 200),
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/pettest/collections/pets*' => Http::response([
                'pets' => [
                    [
                        'id' => 999,
                        'species' => ['id' => 50, 'name' => 'Cat'],
                        'name' => 'Whiskers',
                        'level' => 25,
                        'quality' => ['type' => 'RARE'],
                        'is_favorite' => false,
                    ],
                ],
            ], 200),
            'eu.api.blizzard.com/profile/wow/character/tarren-mill/pettest/collections/toys*' => Http::response(['toys' => []], 200),
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'pettest', SyncDepth::Full);

        $this->assertGreaterThan(0, CharacterMount::count(), 'mounts should be written even when pets flag is off');
        $this->assertSame(0, CharacterPet::count(), 'no pet rows expected when pets flag is off');
    }

    // -------------------------------------------------------------------------
    // Shared fixture helpers
    // -------------------------------------------------------------------------

    private function minimalCharacterPoolResponse(): array
    {
        return [
            'id' => 1,
            'name' => 'Testchar',
            'gender' => ['type' => 'MALE', 'name' => 'Male'],
            'faction' => ['type' => 'ALLIANCE', 'name' => 'Alliance'],
            'race' => ['id' => 1, 'name' => 'Human'],
            'character_class' => ['id' => 1, 'name' => 'Warrior'],
            'level' => 80,
            'achievement_points' => 100,
            'average_item_level' => 500,
            'equipped_item_level' => 490,
            'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
        ];
    }
}
