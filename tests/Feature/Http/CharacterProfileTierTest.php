<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * meta.profile_tier tells the FE whether this character gets the full slice
 * treatment ('full', endgame) or profile-only tracking ('basic', sub-max) —
 * the FE keys the below-max-level notice and tab gating off it.
 */
class CharacterProfileTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('blizzard.endgame_level', 90);
    }

    public function test_submax_character_reports_basic_tier_profile_only(): void
    {
        Character::factory()->create([
            'name' => 'lowbie',
            'realm' => 'azshara',
            'region' => 'eu',
            'level' => 89,
        ]);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/lowbie');
        $resp->assertOk();

        $resp->assertJsonPath('meta.profile_tier', 'basic');

        // Null slice timestamps must not read as "syncing" — sub-max
        // characters are complete once their profile has synced.
        $resp->assertJsonPath('meta.sync_status', 'complete');
        $resp->assertHeaderMissing('X-Sync-Status');
        $this->assertSame(['profile'], array_keys($resp->json('meta.freshness')));

        // Slice relations are not eager-loaded for basic tier — the keys are
        // omitted from the payload entirely (whenLoaded).
        foreach (['pvp_brackets', 'professions', 'raid_progress', 'titles', 'reputations', 'dungeon_runs'] as $key) {
            $this->assertArrayNotHasKey($key, $resp->json('data'), "{$key} must be omitted for basic-tier characters");
        }
    }

    public function test_endgame_character_reports_full_tier(): void
    {
        $now = now();
        Character::factory()->create([
            'name' => 'mainchar',
            'realm' => 'azshara',
            'region' => 'eu',
            'level' => 90,
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

        $resp = $this->getJson('/api/v1/characters/eu/azshara/mainchar');
        $resp->assertOk();

        $resp->assertJsonPath('meta.profile_tier', 'full');
        $this->assertArrayHasKey('mythic_plus', $resp->json('meta.freshness'));
        $this->assertArrayHasKey('pvp_brackets', $resp->json('data'));
    }
}
