<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET .../{character}?refresh=1 — per-entity cooldown grant, nonced sync
 * dedupe bypass, and not-found recovery. Task 7 (FE) consumes the exact
 * meta.refresh = {available, available_at, cooldown_seconds} shape produced
 * here, so field names/shape are load-bearing.
 */
class CharacterRefreshEndpointTest extends TestCase
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

    private function makeFreshEndgameCharacter(string $name = 'cirna'): Character
    {
        $now = now();

        return Character::create([
            'name' => $name,
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'game_version' => 'retail',
            'faction' => 'Alliance',
            'race_id' => 4,
            'class_id' => 5,
            'level' => 90,
            'achievement_points' => 12000,
            'average_item_level' => 640,
            'equipped_item_level' => 640,
            'mythics_synced_at' => $now,
            'pvp_synced_at' => $now,
            'professions_synced_at' => $now,
            'raids_synced_at' => $now,
            'stats_synced_at' => $now,
            'titles_synced_at' => $now,
            'reputations_synced_at' => $now,
            'collections_synced_at' => $now,
            'achievements_synced_at' => $now,
        ]);
    }

    public function test_force_refresh_on_fresh_endgame_character_dispatches_full_and_reports_meta(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-18 12:00:00');

        $this->makeFreshEndgameCharacter();

        $response = $this->getJson('/api/v1/characters/eu/the-maelstrom/cirna?refresh=1');

        $response->assertOk();
        $response->assertJsonPath('meta.forced_refresh', true);
        $response->assertJsonPath('meta.refresh.available', false);
        $response->assertJsonPath('meta.refresh.cooldown_seconds', 300);

        $availableAt = Carbon::parse($response->json('meta.refresh.available_at'));
        $this->assertEqualsWithDelta(
            now()->addSeconds(300)->getTimestamp(),
            $availableAt->getTimestamp(),
            2,
            'available_at must be ~ now + cooldown_seconds',
        );

        Queue::assertPushed(SyncCharacterData::class, 1);
        Queue::assertPushed(SyncCharacterData::class, fn (SyncCharacterData $job) => $job->depth === SyncDepth::Full
            && $job->refreshNonce !== null);
    }

    public function test_immediate_second_force_refresh_is_cooled_down_and_does_not_dispatch_again(): void
    {
        Queue::fake();

        $this->makeFreshEndgameCharacter();

        $this->getJson('/api/v1/characters/eu/the-maelstrom/cirna?refresh=1')->assertOk();
        Queue::assertPushed(SyncCharacterData::class, 1);

        $second = $this->getJson('/api/v1/characters/eu/the-maelstrom/cirna?refresh=1');
        $second->assertOk();
        $second->assertJsonPath('meta.forced_refresh', false);
        $second->assertJsonPath('meta.refresh.available', false);

        // Still exactly 1 — the cooldown denied the grant, so no second
        // dispatch happened, and the character was already fresh so no
        // ordinary staleness dispatch fires either.
        Queue::assertPushed(SyncCharacterData::class, 1);
    }

    public function test_force_refresh_recovers_from_not_found_marker_returning_202_not_404(): void
    {
        Cache::put('blizzard:not-found:character:eu:the-maelstrom:zzz', true, 60);

        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz?refresh=1')
            ->assertStatus(202);
    }

    public function test_plain_get_without_refresh_param_reports_refresh_available(): void
    {
        $this->makeFreshEndgameCharacter('freshie');

        $response = $this->getJson('/api/v1/characters/eu/the-maelstrom/freshie');

        $response->assertOk();
        $response->assertJsonPath('meta.forced_refresh', false);
        $response->assertJsonPath('meta.refresh.available', true);
        $response->assertJsonPath('meta.refresh.available_at', null);
        $response->assertJsonPath('meta.refresh.cooldown_seconds', 300);
    }
}
