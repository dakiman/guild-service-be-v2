<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fast structural test for GET /api/v1/guilds/{region}/{realm}/{name}.
 * Pre-seeds a fresh guild + members so the controller does not dispatch
 * the live SyncGuildData job. The slow live-API equivalent lives in
 * tests/Feature/Endpoints/GuildEndpointTest (group: integration).
 */
class GuildShowEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_guild_and_paginated_members_with_enriched_fields(): void
    {
        $guild = Guild::factory()->create([
            'name' => 'testguild',
            'realm' => 'test-realm',
            'region' => 'eu',
            'roster_synced_at' => now(),
        ]);

        // Touch updated_at to "now" so Guild::isStale() returns false and the
        // controller skips both the SyncGuildData dispatch and the staleness header.
        $guild->forceFill(['updated_at' => now()])->save();

        // One synced member (links to a Character row), one unsynced member.
        $character = Character::factory()->create([
            'name' => 'syncedmember',
            'realm' => 'test-realm',
            'region' => 'eu',
            'equipped_item_level' => 619,
            'mythic_plus_rating' => 2543,
            'mythic_plus_rating_color' => '#a335ee',
            'active_specialization_id' => 73,
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'name' => 'syncedmember',
            'realm' => 'test-realm',
            'level' => 80,
            'class_id' => 1,
            'race_id' => 1, // Human → Alliance
            'rank' => 1,
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => null,
            'name' => 'unsyncedmember',
            'realm' => 'test-realm',
            'level' => 80,
            'class_id' => 8,
            'race_id' => 5, // Undead → Horde
            'rank' => 5,
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/test-realm/testguild');

        $response->assertOk();

        $response->assertJsonStructure([
            'guild' => [
                'id', 'name', 'realm', 'region', 'faction',
                'member_count', 'achievement_points',
            ],
            'members' => [
                'current_page', 'data', 'last_page', 'per_page', 'total',
            ],
        ]);

        $this->assertSame('eu', $response->json('guild.region'));

        $members = $response->json('members.data');
        $this->assertIsArray($members);
        $this->assertCount(2, $members);

        $synced = collect($members)->firstWhere('name', 'syncedmember');
        $this->assertNotNull($synced);
        foreach ([
            'id', 'guild_id', 'name', 'realm', 'level', 'class_id', 'race_id', 'rank',
            'faction', 'equipped_item_level', 'mythic_plus_rating',
            'active_specialization_id', 'synced_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $synced, "synced row missing key: {$key}");
        }
        $this->assertSame('Alliance', $synced['faction']);
        $this->assertSame(619, $synced['equipped_item_level']);
        $this->assertSame(2543, $synced['mythic_plus_rating']['rating']);
        $this->assertSame('#a335ee', $synced['mythic_plus_rating']['color']);
        $this->assertSame(73, $synced['active_specialization_id']);
        $this->assertNotNull($synced['synced_at']);

        $unsynced = collect($members)->firstWhere('name', 'unsyncedmember');
        $this->assertNotNull($unsynced);
        $this->assertSame('Horde', $unsynced['faction']);
        $this->assertNull($unsynced['equipped_item_level']);
        $this->assertNull($unsynced['mythic_plus_rating']);
        $this->assertNull($unsynced['active_specialization_id']);
        $this->assertNull($unsynced['synced_at']);
    }

    public function test_endpoint_returns_character_data_via_character_id_fk(): void
    {
        // Verifies that character data flows through via the character_id FK eager-load
        // (the stitch-by-tuple workaround has been removed; character_id must be wired).
        $guild = Guild::factory()->create([
            'name' => 'linkedguild',
            'realm' => 'test-realm',
            'region' => 'eu',
            'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();

        $character = Character::factory()->create([
            'name' => 'linkedmember',
            'realm' => 'test-realm',
            'region' => 'eu',
            'equipped_item_level' => 542,
            'mythic_plus_rating' => 1800,
            'mythic_plus_rating_color' => '#0070dd',
            'active_specialization_id' => 105,
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'name' => 'linkedmember',
            'realm' => 'test-realm',
            'level' => 80,
            'class_id' => 11,
            'race_id' => 4,
            'rank' => 3,
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/test-realm/linkedguild');
        $response->assertOk();

        $row = collect($response->json('members.data'))->firstWhere('name', 'linkedmember');
        $this->assertNotNull($row);
        $this->assertSame(542, $row['equipped_item_level']);
        $this->assertSame(1800, $row['mythic_plus_rating']['rating']);
        $this->assertSame('#0070dd', $row['mythic_plus_rating']['color']);
        $this->assertSame(105, $row['active_specialization_id']);
        $this->assertNotNull($row['synced_at']);
    }

    public function test_endpoint_filter_query_narrows_member_list(): void
    {
        $guild = Guild::factory()->create([
            'name' => 'filterguild',
            'realm' => 'test-realm',
            'region' => 'eu',
            'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();

        foreach (['alpha', 'beta', 'alphabeto', 'gamma'] as $name) {
            GuildMember::factory()->create([
                'guild_id' => $guild->id,
                'name' => $name,
                'realm' => 'test-realm',
                'level' => 80,
                'class_id' => 1,
                'race_id' => 1,
                'rank' => 5,
            ]);
        }

        $response = $this->getJson('/api/v1/guilds/eu/test-realm/filterguild?filter=alpha');
        $response->assertOk();

        $names = collect($response->json('members.data'))->pluck('name')->all();
        sort($names);
        $this->assertSame(['alpha', 'alphabeto'], $names);
    }
}
