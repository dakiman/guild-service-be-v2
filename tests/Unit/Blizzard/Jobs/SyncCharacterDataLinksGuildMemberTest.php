<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCharacterDataLinksGuildMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_existing_guild_member_in_same_region_after_character_upsert(): void
    {
        $guildEu = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'echo']);
        $member = GuildMember::factory()->create([
            'guild_id' => $guildEu->id,
            'character_id' => null,
            'name' => 'delta',
            'realm' => 'tarren-mill',
        ]);

        $character = Character::factory()->create([
            'name' => 'delta',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        SyncCharacterData::linkGuildMembers($character);

        $this->assertSame($character->id, $member->fresh()->character_id);
    }

    public function test_does_not_link_guild_member_in_different_region(): void
    {
        $guildUs = Guild::factory()->create(['region' => 'us', 'realm' => 'tarren-mill', 'name' => 'echo-us']);
        $member = GuildMember::factory()->create([
            'guild_id' => $guildUs->id,
            'character_id' => null,
            'name' => 'epsilon',
            'realm' => 'tarren-mill',
        ]);

        $character = Character::factory()->create([
            'name' => 'epsilon',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        SyncCharacterData::linkGuildMembers($character);

        $this->assertNull($member->fresh()->character_id, 'cross-region link must not happen');
    }

    public function test_no_op_when_guild_member_already_linked(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        $other = Character::factory()->create([
            'name' => 'zeta-old', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        $member = GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => $other->id,
            'name' => 'zeta',
            'realm' => 'tarren-mill',
        ]);

        $current = Character::factory()->create([
            'name' => 'zeta', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);

        SyncCharacterData::linkGuildMembers($current);

        $this->assertSame($other->id, $member->fresh()->character_id);
    }
}
