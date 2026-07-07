<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\ProactiveSyncCharacters;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncFanoutLaneRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config()->set('raiderio.character_resync_ttl', 86400);
        config()->set('blizzard.endgame_level', 90);
    }

    public function test_roster_fanout_never_touches_the_user_sync_lane(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'coldmember', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'coldmember'
                && $j->origin === SyncOrigin::RosterFanout
                && $j->queue === 'blizzard-roster-sync',
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->queue === 'blizzard-user-sync',
        );
    }

    public function test_shallow_fanout_on_proactive_path_also_uses_roster_lane(): void
    {
        // forceFanout=false + config flag off → Shallow-only path.
        config()->set('raiderio.dispatch_roster_character_syncs', false);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'shallowmember', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        (new SyncGuildRoster($guild, forceFanout: false))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'shallowmember'
                && $j->queue === 'blizzard-roster-sync',
        );
    }

    public function test_proactive_sync_dispatches_to_background_lane(): void
    {
        $character = Character::factory()->create([
            'region' => 'eu', 'realm' => 'silvermoon', 'name' => 'popularchar',
            'game_version' => 'retail',
            'num_of_searches' => 10,
            'last_searched_at' => now()->subDay(),
            'last_login_at' => now()->subDay(),
        ]);

        (new ProactiveSyncCharacters(tier: 1))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'popularchar'
                && $j->origin === SyncOrigin::Proactive
                && $j->queue === 'blizzard-background',
        );
    }
}
