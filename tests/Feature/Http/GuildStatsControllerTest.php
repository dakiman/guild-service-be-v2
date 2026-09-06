<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\DungeonRunMember;
use App\Models\GameDataSeason;
use App\Models\Guild;
use App\Models\RaidEncounterKill;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createGuild(array $attributes = []): Guild
    {
        return Guild::factory()->create(array_merge([
            'name' => 'test-guild',
            'realm' => 'test-realm',
            'region' => 'eu',
        ], $attributes));
    }

    private function createActiveGuildMember(Guild $guild, array $attributes = []): Character
    {
        $character = Character::factory()->create(array_merge([
            'guild_id' => $guild->id,
            'region' => $guild->region,
        ], $attributes));

        RaidEncounterKill::factory()->create(['character_id' => $character->id]);

        return $character;
    }

    public function test_returns_guild_stats(): void
    {
        $guild = $this->createGuild();

        $char1 = $this->createActiveGuildMember($guild, [
            'name' => 'tankchar',
            'realm' => 'test-realm',
            'average_item_level' => 620,
            'mythic_plus_rating' => 2800,
            'class_id' => 1,
            'active_specialization_id' => 73, // Protection Warrior = tank
        ]);

        $char2 = $this->createActiveGuildMember($guild, [
            'name' => 'healchar',
            'display_name' => 'Healchar',
            'realm' => 'test-realm',
            'average_item_level' => 630,
            'mythic_plus_rating' => 3200,
            'class_id' => 2,
            'active_specialization_id' => 65, // Holy Paladin = healer
        ]);

        // Create a dungeon run with a guild member participating
        $run = DungeonRun::factory()->create([
            'dungeon_id' => 500,
            'dungeon_name' => 'The Stonevault',
            'keystone_level' => 22,
            'is_completed_on_time' => true,
        ]);

        DungeonRunMember::create([
            'dungeon_run_id' => $run->id,
            'character_id' => $char1->id,
            'character_name' => $char1->name,
            'character_realm' => $char1->realm,
            'character_region' => $char1->region,
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/test-realm/test-guild/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'member_count',
                'avg_item_level',
                'avg_mythic_plus_rating',
                'top_mythic_plus' => ['rating', 'character' => ['name', 'display_name', 'realm', 'region', 'class_id']],
                'role_coverage' => ['tank', 'healer', 'dps'],
                'best_keys' => [['dungeon_id', 'dungeon_name', 'key_level']],
            ]);

        $data = $response->json();

        $this->assertEquals(2, $data['member_count']);
        $this->assertEquals(625.0, $data['avg_item_level']);
        $this->assertEquals(3000.0, $data['avg_mythic_plus_rating']);

        // Top M+ should be char2 (3200 rating)
        $this->assertEquals(3200, $data['top_mythic_plus']['rating']);
        $this->assertEquals('healchar', $data['top_mythic_plus']['character']['name']);
        $response->assertJsonPath('top_mythic_plus.character.name', 'healchar')
            ->assertJsonPath('top_mythic_plus.character.display_name', 'Healchar');

        // Role coverage: 1 tank, 1 healer, 0 dps
        $this->assertEquals(1, $data['role_coverage']['tank']);
        $this->assertEquals(1, $data['role_coverage']['healer']);
        $this->assertEquals(0, $data['role_coverage']['dps']);

        // Best keys: one entry for The Stonevault at level 22
        $this->assertCount(1, $data['best_keys']);
        $this->assertEquals(500, $data['best_keys'][0]['dungeon_id']);
        $this->assertEquals('The Stonevault', $data['best_keys'][0]['dungeon_name']);
        $this->assertEquals(22, $data['best_keys'][0]['key_level']);
    }

    public function test_returns_404_for_unknown_guild(): void
    {
        $response = $this->getJson('/api/v1/guilds/eu/nonexistent-realm/nonexistent-guild/stats');

        $response->assertNotFound();
    }

    public function test_resolves_guild_when_url_segments_are_not_normalized(): void
    {
        $guild = $this->createGuild(['realm' => 'test-realm', 'name' => 'test-guild']);
        $this->createActiveGuildMember($guild, ['name' => 'memberchar', 'realm' => 'test-realm']);

        // GuildController::show slugifies realm + guild; stats must apply the
        // same normalization so a URL that works for the guild page resolves
        // here too instead of 404ing. (P1.4)
        $response = $this->getJson('/api/v1/guilds/eu/TEST-REALM/TEST-GUILD/stats');

        $response->assertOk();
    }

    public function test_best_keys_only_counts_current_season_runs(): void
    {
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'Midnight Season 2',
            'raiderio_tier_slug' => 'tier-mn-2', 'raiderio_expansion_id' => 11, 'is_current' => true,
        ]);
        Seasons::clearCache();

        $guild = $this->createGuild();
        $char = $this->createActiveGuildMember($guild, ['name' => 'keychar', 'realm' => 'test-realm']);

        $current = DungeonRun::factory()->create([
            'season' => 18, 'dungeon_id' => 600, 'dungeon_name' => 'Current Dungeon',
            'keystone_level' => 12, 'is_completed_on_time' => true,
        ]);
        $old = DungeonRun::factory()->create([
            'season' => 17, 'dungeon_id' => 500, 'dungeon_name' => 'Old Dungeon',
            'keystone_level' => 25, 'is_completed_on_time' => true,
        ]);
        DungeonRunMember::create([
            'dungeon_run_id' => $current->id,
            'character_id' => $char->id,
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
        ]);
        DungeonRunMember::create([
            'dungeon_run_id' => $old->id,
            'character_id' => $char->id,
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
        ]);

        $data = $this->getJson('/api/v1/guilds/eu/test-realm/test-guild/stats')->assertOk()->json();

        $this->assertCount(1, $data['best_keys']);
        $this->assertSame(600, $data['best_keys'][0]['dungeon_id']);
    }
}
