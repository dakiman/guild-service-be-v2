<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildControllerEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_member_character_columns_via_eager_load(): void
    {
        $guild = Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'echo',
            'roster_synced_at' => now(),
            'updated_at' => now(),
        ]);

        $character = Character::factory()->create([
            'name' => 'wired',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'equipped_item_level' => 642,
            'mythic_plus_rating' => 3210,
            'mythic_plus_rating_color' => '#ff8000',
            'active_specialization_id' => 71,
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'name' => 'wired',
            'realm' => 'tarren-mill',
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');

        $response->assertOk();
        $response->assertJsonPath('members.data.0.equipped_item_level', 642);
        // GuildMemberResource emits mythic_plus_rating as a nested {rating, color} object
        // (verified in app/Http/Resources/GuildMemberResource.php).
        $response->assertJsonPath('members.data.0.mythic_plus_rating.rating', 3210);
        $response->assertJsonPath('members.data.0.mythic_plus_rating.color', '#ff8000');
        $response->assertJsonPath('members.data.0.active_specialization_id', 71);
    }

    public function test_show_returns_null_member_columns_when_character_id_is_null(): void
    {
        $guild = Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'echo',
            'roster_synced_at' => now(),
            'updated_at' => now(),
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => null,
            'name' => 'unlinked',
            'realm' => 'tarren-mill',
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');

        $response->assertOk();
        $response->assertJsonPath('members.data.0.equipped_item_level', null);
        $response->assertJsonPath('members.data.0.mythic_plus_rating', null);
    }

    public function test_show_backfills_character_id_when_matching_character_exists(): void
    {
        $guild = Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'echo',
            'roster_synced_at' => now(),
            'updated_at' => now(),
        ]);

        // Character was synced (e.g., teammate-crawl) AFTER the last
        // SyncGuildData run, so the GuildMember row never got linked.
        $character = Character::factory()->create([
            'name' => 'late-arrival',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'equipped_item_level' => 642,
            'mythic_plus_rating' => 3210,
            'mythic_plus_rating_color' => '#ff8000',
            'active_specialization_id' => 71,
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => null,
            'name' => 'late-arrival',
            'realm' => 'tarren-mill',
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');

        $response->assertOk();
        $response->assertJsonPath('members.data.0.equipped_item_level', 642);
        $response->assertJsonPath('members.data.0.mythic_plus_rating.rating', 3210);
        $response->assertJsonPath('members.data.0.mythic_plus_rating.color', '#ff8000');
        $response->assertJsonPath('members.data.0.active_specialization_id', 71);

        // And the row is now linked on disk — self-healing.
        $this->assertSame(
            $character->id,
            GuildMember::where('guild_id', $guild->id)->where('name', 'late-arrival')->value('character_id'),
        );
    }
}
