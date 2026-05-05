<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Services\CharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterSyncStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_includes_syncing_status_when_slices_never_synced(): void
    {
        Character::factory()->create([
            'name' => 'newchar',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'mythics_synced_at' => null,
            'pvp_synced_at' => null,
            'stats_synced_at' => null,
        ]);

        $response = $this->getJson('/api/v1/characters/eu/tarren-mill/newchar');

        $response->assertOk();
        $response->assertJsonPath('meta.sync_status', 'syncing');
        $response->assertJsonPath('meta.poll_after', 30);
        $response->assertJsonStructure(['meta' => ['queue_depth']]);
        $response->assertHeader('X-Sync-Status', 'syncing');
        $response->assertHeader('Retry-After', '30');
    }

    public function test_response_includes_complete_status_when_all_slices_synced(): void
    {
        Character::factory()->create([
            'name' => 'veteran',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'mythics_synced_at' => now(),
            'pvp_synced_at' => now(),
            'professions_synced_at' => now(),
            'raids_synced_at' => now(),
            'stats_synced_at' => now(),
            'titles_synced_at' => now(),
            'reputations_synced_at' => now(),
            'collections_synced_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/characters/eu/tarren-mill/veteran');

        $response->assertOk();
        $response->assertJsonPath('meta.sync_status', 'complete');
        $response->assertJsonMissing(['poll_after' => 30]);
        $response->assertHeaderMissing('X-Sync-Status');
    }

    public function test_sync_status_header_coexists_with_data_staleness_header(): void
    {
        $character = Character::factory()->create([
            'name' => 'stalechar',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'mythics_synced_at' => null,
        ]);

        // Mock the service to return a character with stale updated_at
        // (normally the service refreshes updated_at on every lookup).
        $this->mock(CharacterService::class, function ($mock) use ($character) {
            $character->updated_at = now()->subHour();
            $character->wasRecentlyCreated = false;

            $mock->shouldReceive('getByIdentity')
                ->once()
                ->andReturn($character);
        });

        $response = $this->getJson('/api/v1/characters/eu/tarren-mill/stalechar');

        $response->assertOk();
        $response->assertHeader('X-Sync-Status', 'syncing');
        $response->assertHeader('X-Data-Staleness', 'stale');
    }
}
