<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * In-job invariant: a Full-depth job whose fetched profile is below endgame
 * level must skip all nine slice syncs, whatever lane dispatched it (teammate
 * crawl, seeder, stale dispatch-site gate). Lane gates save volume; this guard
 * enforces correctness.
 */
class SyncCharacterDataSubMaxFullGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SLICE_TIMESTAMPS = [
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
        'stats_synced_at',
        'titles_synced_at',
        'reputations_synced_at',
        'collections_synced_at',
        'achievements_synced_at',
    ];

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
    }

    public function test_full_sync_of_submax_character_skips_all_slices(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*' => Http::response([
                'id' => 1,
                'name' => 'Lowbie',
                'gender' => ['type' => 'MALE', 'name' => 'Male'],
                'faction' => ['type' => 'ALLIANCE', 'name' => 'Alliance'],
                'race' => ['id' => 1, 'name' => 'Human'],
                'character_class' => ['id' => 1, 'name' => 'Warrior'],
                'level' => 45,
                'achievement_points' => 100,
                'average_item_level' => 100,
                'equipped_item_level' => 90,
                'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
            ], 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'lowbie', SyncDepth::Full);

        $character = Character::where('name', 'lowbie')->where('realm', 'tarren-mill')->first();
        $this->assertNotNull($character);
        $this->assertSame(45, $character->level);

        foreach (self::SLICE_TIMESTAMPS as $field) {
            $this->assertNull($character->{$field}, "{$field} must stay null for a sub-endgame Full sync");
        }

        // No slice endpoint may be hit — the guard skips before any slice fetch.
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/encounters/raids'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/statistics'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/reputations'));
    }
}
