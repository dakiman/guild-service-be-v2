<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The `Character::updateOrCreate` write site is the last chokepoint before a
 * row is persisted: jobs serialized before the canonicalization deploy still
 * carry raw-cased names (unserialize bypasses the constructor), so the keys
 * must be canonicalized here regardless of the dispatch path.
 */
class SyncCharacterDataCanonicalNameTest extends TestCase
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
    }

    /**
     * Only the `basic` slot of the character pool answers; the four sibling
     * endpoints 404 so `poolJson()` yields null and no other mapper runs.
     */
    private function fakeProfile(string $displayName): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/character-media*' => Http::response(['code' => 404], 404),
            'eu.api.blizzard.com/profile/wow/character/*/equipment*' => Http::response(['code' => 404], 404),
            'eu.api.blizzard.com/profile/wow/character/*/specializations*' => Http::response(['code' => 404], 404),
            'eu.api.blizzard.com/profile/wow/character/*/mythic-keystone-profile*' => Http::response(['code' => 404], 404),
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response([
                'name' => $displayName,
                'gender' => ['name' => 'Female'],
                'faction' => ['name' => 'Horde'],
                'race' => ['id' => 8],
                'character_class' => ['id' => 11],
                // Sub-endgame on purpose: keeps the Shallow/Standard -> Full
                // promotion (and its extra dispatch) out of this test.
                'level' => 80,
                'achievement_points' => 1000,
                'average_item_level' => 600,
                'equipped_item_level' => 600,
                'realm' => ['name' => 'Howling Fjord', 'slug' => 'howling-fjord'],
            ], 200),
        ]);
    }

    public function test_capitalized_dispatch_upserts_into_canonical_row(): void
    {
        $existing = Character::factory()->create([
            'name' => 'бробабади',
            'realm' => 'howling-fjord',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $this->fakeProfile('Бробабади');

        // Dispatched with the DISPLAY-CASED name, as a pre-deploy queued
        // payload (or any ASCII-only strtolower caller) would.
        SyncCharacterData::dispatchSync('eu', 'howling-fjord', 'Бробабади');

        $this->assertSame(1, Character::query()
            ->where('realm', 'howling-fjord')
            ->where('region', 'eu')
            ->count(), 'a capitalized dispatch must not create a second row');

        $fresh = $existing->fresh();
        $this->assertSame('бробабади', $fresh->name);
        $this->assertSame('Бробабади', $fresh->display_name, 'display_name keeps the raw Blizzard casing');
    }

    public function test_uppercase_realm_is_canonicalized_at_the_write_site(): void
    {
        $this->fakeProfile('Бробабади');

        SyncCharacterData::dispatchSync('eu', 'Howling Fjord', 'Бробабади');

        $this->assertDatabaseHas('characters', [
            'name' => 'бробабади',
            'realm' => 'howling-fjord',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        $this->assertSame(1, Character::query()->count());
    }
}
