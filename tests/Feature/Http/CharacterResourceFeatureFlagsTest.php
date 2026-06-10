<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CharacterResourceFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        $this->character = Character::factory()->create([
            'name' => 'flagtest',
            'realm' => 'azshara',
            'region' => 'eu',
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

    public function test_meta_feature_flags_both_false_by_default(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);
        Config::set('blizzard.sync.pets_enabled', false);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/flagtest');
        $resp->assertOk();

        $resp->assertJsonPath('meta.feature_flags.achievements', false);
        $resp->assertJsonPath('meta.feature_flags.pets', false);
    }

    public function test_meta_feature_flags_both_true_when_flags_on(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);
        Config::set('blizzard.sync.pets_enabled', true);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/flagtest');
        $resp->assertOk();

        $resp->assertJsonPath('meta.feature_flags.achievements', true);
        $resp->assertJsonPath('meta.feature_flags.pets', true);
    }

    public function test_freshness_drops_achievements_key_when_flag_off(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/flagtest');
        $resp->assertOk();

        $freshness = $resp->json('meta.freshness');
        $this->assertArrayNotHasKey('achievements', $freshness, 'achievements freshness key should be absent when flag is off');
        // Other freshness keys should still be present.
        $this->assertArrayHasKey('profile', $freshness);
        $this->assertArrayHasKey('collections', $freshness);
    }

    public function test_freshness_includes_achievements_key_when_flag_on(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $resp = $this->getJson('/api/v1/characters/eu/azshara/flagtest');
        $resp->assertOk();

        $freshness = $resp->json('meta.freshness');
        $this->assertArrayHasKey('achievements', $freshness, 'achievements freshness key should be present when flag is on');
    }
}
