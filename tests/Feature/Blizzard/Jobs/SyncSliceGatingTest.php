<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\CharacterAchievement;
use App\Models\CharacterMount;
use App\Models\CharacterPet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the feature-flag gating of the achievements and pets slices.
 *
 * The job is dispatched synchronously (QUEUE_CONNECTION=sync in phpunit.xml).
 * Http::fake() intercepts all outbound HTTP calls so no real network traffic occurs.
 */
class SyncSliceGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub token manager — job needs it to build HTTP client auth headers.
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

        // Disable all optional slices by default; individual tests opt in.
        Config::set('blizzard.sync.mythic_plus_enabled', false);
        Config::set('blizzard.sync.pvp_enabled', false);
        Config::set('blizzard.sync.professions_enabled', false);
        Config::set('blizzard.sync.raids_enabled', false);
        Config::set('blizzard.sync.teammate_crawl_enabled', false);
        Config::set('blizzard.sync.achievements_enabled', false);
        Config::set('blizzard.sync.pets_enabled', false);
    }

    // -------------------------------------------------------------------------
    // Achievements slice
    // -------------------------------------------------------------------------

    /**
     * When BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED is false, syncAchievements() must
     * return before making any HTTP call to the achievements endpoint.
     */
    public function test_sync_achievements_skips_http_and_db_write_when_flag_off(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);

        Http::fake([
            // The achievements endpoint is deliberately NOT registered here.
            // Http::fake() with a strict wildcard-only map would 500 any call
            // to the achievements URL — but we assert via assertNotSent too.
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'gatetest', SyncDepth::Full);

        $this->assertSame(0, CharacterAchievement::count(), 'no achievement rows expected when flag is off');
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/achievements'));
    }

    // -------------------------------------------------------------------------
    // Pets slice
    // -------------------------------------------------------------------------

    /**
     * When BLIZZARD_SYNC_PETS_ENABLED is false, syncCollections() still performs
     * the HTTP fetch (mounts + pets + toys pool), but writes NO pet rows.
     * Mount rows should still be written to confirm the rest of the slice ran.
     */
    public function test_sync_collections_writes_mounts_but_not_pets_when_pets_flag_off(): void
    {
        Config::set('blizzard.sync.pets_enabled', false);

        Http::fake([
            // The trailing * is required: Laravel's Http::fake URL matching prepends a
            // wildcard prefix but not a suffix, so without it query parameters
            // (?namespace=...&locale=...) cause the specific pattern to miss and fall
            // through to the wildcard catch-all below.
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
            // Catch-all for standard-depth pool + stats/titles/reputations slices.
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'pettest', SyncDepth::Full);

        $this->assertGreaterThan(0, CharacterMount::count(), 'mounts should be written even when pets flag is off');
        $this->assertSame(0, CharacterPet::count(), 'no pet rows expected when pets flag is off');
    }

    // -------------------------------------------------------------------------
    // Shared fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Minimal Blizzard profile response that satisfies CharacterProfileMapper.
     * Used as the fallback wildcard response for the standard-depth pool requests.
     */
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
