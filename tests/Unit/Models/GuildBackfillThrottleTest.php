<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * backfillMemberCharacterIds runs a correlated-subquery UPDATE on every guild
 * page view. It must self-throttle so the scan happens at most once per window
 * per guild, while still linking members that already have a Character. (P2.4)
 */
final class GuildBackfillThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function makeGuildWithUnlinkedMember(string $memberName): Guild
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'kazzak']);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'name' => $memberName,
            'realm' => 'kazzak',
            'rank' => 1,
            'character_id' => null,
        ]);

        Character::factory()->create([
            'name' => $memberName, 'realm' => 'kazzak', 'region' => 'eu', 'game_version' => 'retail',
        ]);

        return $guild;
    }

    public function test_first_call_links_members(): void
    {
        $guild = $this->makeGuildWithUnlinkedMember('alice');

        $guild->backfillMemberCharacterIds(throttled: true);

        $this->assertNotNull(GuildMember::where('name', 'alice')->value('character_id'));
    }

    public function test_second_call_within_window_is_skipped(): void
    {
        $guild = $this->makeGuildWithUnlinkedMember('alice');
        $guild->backfillMemberCharacterIds(throttled: true);

        // A new unlinked member appears; an immediate re-view must NOT re-run the scan.
        GuildMember::factory()->create(['guild_id' => $guild->id, 'name' => 'bob', 'realm' => 'kazzak', 'rank' => 2, 'character_id' => null]);
        Character::factory()->create(['name' => 'bob', 'realm' => 'kazzak', 'region' => 'eu', 'game_version' => 'retail']);

        $guild->backfillMemberCharacterIds(throttled: true);

        $this->assertNull(GuildMember::where('name', 'bob')->value('character_id'));
    }

    public function test_runs_again_after_window_elapses(): void
    {
        $guild = $this->makeGuildWithUnlinkedMember('alice');
        $guild->backfillMemberCharacterIds(throttled: true);

        GuildMember::factory()->create(['guild_id' => $guild->id, 'name' => 'bob', 'realm' => 'kazzak', 'rank' => 2, 'character_id' => null]);
        Character::factory()->create(['name' => 'bob', 'realm' => 'kazzak', 'region' => 'eu', 'game_version' => 'retail']);

        $this->travel(11)->minutes();
        $guild->backfillMemberCharacterIds(throttled: true);

        $this->assertNotNull(GuildMember::where('name', 'bob')->value('character_id'));
    }
}
