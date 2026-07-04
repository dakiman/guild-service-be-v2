<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncGuildRosterTtlGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config()->set('raiderio.character_resync_ttl', 86400);
        config()->set('blizzard.min_level_for_character_lookup', 70);
    }

    public function test_skips_shallow_and_full_for_fresh_member_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'fresh', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        Character::factory()->create([
            'name' => 'fresh', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        Character::where('name', 'fresh')->update(['updated_at' => now()->subMinutes(5)]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'fresh',
        );
    }

    public function test_dispatches_full_only_for_stale_member_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'stale', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        Character::factory()->create([
            'name' => 'stale', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        Character::where('name', 'stale')->update(['updated_at' => now()->subDays(2)]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        // Full is a strict superset of Shallow — a member getting a Full must NOT
        // also get a wasted Shallow. (B1 behavior change)
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'stale' && $j->depth === SyncDepth::Full,
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'stale' && $j->depth === SyncDepth::Shallow,
        );
    }

    public function test_dispatches_full_only_for_cold_member_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'cold', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'cold' && $j->depth === SyncDepth::Full,
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'cold' && $j->depth === SyncDepth::Shallow,
        );
    }

    public function test_proactive_with_config_dispatches_full_only_for_stale_and_shallow_only_for_fresh(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', true);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'stale', 'realm' => 'tarren-mill', 'level' => 90,
        ]);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'fresh', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        Character::factory()->create([
            'name' => 'stale', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        Character::where('name', 'stale')->update(['updated_at' => now()->subDays(2)]);

        Character::factory()->create([
            'name' => 'fresh', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        Character::where('name', 'fresh')->update(['updated_at' => now()->subMinutes(5)]);

        (new SyncGuildRoster($guild, forceFanout: false))->handle();

        // Stale → Full only (no wasted Shallow).
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'stale' && $j->depth === SyncDepth::Full,
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'stale' && $j->depth === SyncDepth::Shallow,
        );

        // Fresh → Shallow only (proactive path never skips Shallow on freshness).
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'fresh' && $j->depth === SyncDepth::Shallow,
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'fresh' && $j->depth === SyncDepth::Full,
        );
    }

    public function test_full_dispatches_carry_force_teammate_crawl_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'cold', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'cold'
                && $j->depth === SyncDepth::Full
                && $j->forceTeammateCrawl === true,
        );
    }

    public function test_shallow_still_dispatched_for_cold_member_when_global_flag_off_and_no_force_fanout(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', false);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'proactive', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        (new SyncGuildRoster($guild, forceFanout: false))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'proactive' && $j->depth === SyncDepth::Shallow,
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'proactive' && $j->depth === SyncDepth::Full,
        );
    }
}
