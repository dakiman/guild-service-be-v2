<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\CharacterAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CharacterAchievementsControllerFlagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * When BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED is false, the controller must
     * short-circuit before any DB query and return an empty but correctly-shaped
     * payload (200, not 404).
     */
    public function test_returns_empty_payload_when_achievements_flag_off(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);

        // Create a character WITH achievements to confirm no DB query leaks through.
        $char = Character::factory()->create(['name' => 'herotest', 'realm' => 'azshara', 'region' => 'eu']);
        CharacterAchievement::create([
            'character_id' => $char->id,
            'achievement_id' => 100,
            'completed_timestamp' => 1700000000000,
        ]);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/herotest/achievements');

        $resp->assertOk();
        $resp->assertJsonPath('data', []);
        $resp->assertJsonPath('meta.total', 0);
        $resp->assertJsonPath('meta.next_cursor', null);
        // per_page should fall back to default (100)
        $resp->assertJsonPath('meta.per_page', 100);
    }

    /**
     * When flag is off and per_page is supplied, that value should be reflected
     * in the empty response so the FE can cache its page-size preference.
     */
    public function test_respects_per_page_param_in_empty_response_when_flag_off(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/whoever/achievements?per_page=50');

        $resp->assertOk();
        $resp->assertJsonPath('meta.per_page', 50);
    }

    /**
     * Sanity: when flag is on the controller works normally (existing tests cover
     * the full path; this just confirms the flag-on branch doesn't short-circuit).
     */
    public function test_flag_on_still_returns_404_for_missing_character(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $this->getJson('/api/v1/characters/eu/azshara/nobody/achievements')
            ->assertNotFound();
    }
}
