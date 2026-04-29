<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CharacterControllerNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

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

    public function test_returns_404_immediately_when_cache_marker_set(): void
    {
        Cache::put('blizzard:not-found:character:eu:the-maelstrom:zzz', true, 60);

        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Character not found']);
    }

    public function test_first_call_dispatches_sync_then_second_call_returns_404_after_marker(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        // First request: 202 + sync runs synchronously (queue=sync) → marker written
        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz')
            ->assertStatus(202)
            ->assertHeader('Retry-After', '5');

        $this->assertSame(0, Character::query()->count(), 'no garbage row');
        $this->assertTrue(Cache::has('blizzard:not-found:character:eu:the-maelstrom:zzz'));

        // Second request: 404 immediately
        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz')
            ->assertStatus(404);
    }

    public function test_mixed_case_url_finds_existing_lowercased_row(): void
    {
        Http::fake();

        $now = now();

        Character::create([
            'name' => 'cirna',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'game_version' => 'retail',
            'faction' => 'Alliance',
            'race_id' => 4,
            'class_id' => 5,
            'level' => 90,
            'achievement_points' => 12000,
            'average_item_level' => 240,
            'equipped_item_level' => 240,
            'mythics_synced_at' => $now,
            'pvp_synced_at' => $now,
            'professions_synced_at' => $now,
            'raids_synced_at' => $now,
            'stats_synced_at' => $now,
            'titles_synced_at' => $now,
            'reputations_synced_at' => $now,
        ]);

        $this->getJson('/api/v1/characters/eu/the-maelstrom/Cirna')
            ->assertOk()
            ->assertJsonPath('data.name', 'cirna');

        $this->assertSame(1, Character::query()->count(), 'no duplicate created');
    }
}
