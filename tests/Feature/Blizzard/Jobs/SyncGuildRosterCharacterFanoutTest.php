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

class SyncGuildRosterCharacterFanoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_stale_member_still_gets_shallow_only_when_no_force_fanout(): void
    {
        // forceFanout is the only Full-fanout switch (the raiderio.dispatch_
        // roster_character_syncs config that used to also gate this was dead
        // and has been removed) — staleness alone can't trigger a Full.
        config()->set('raiderio.character_resync_ttl', 3600);  // 1h TTL
        config()->set('blizzard.endgame_level', 90);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Stale', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);

        Character::factory()->create([
            'name' => 'Stale', 'realm' => 'tarren-mill', 'region' => 'eu',
        ]);
        Character::where(['name' => 'Stale', 'realm' => 'tarren-mill', 'region' => 'eu'])
            ->update(['updated_at' => now()->subHours(2)]);  // older than TTL

        (new SyncGuildRoster($guild))->handle();

        Bus::assertDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Shallow && $job->name === 'Stale');
        Bus::assertNotDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full && $job->name === 'Stale');
    }

    public function test_skips_all_full_dispatches_without_force_fanout(): void
    {
        config()->set('blizzard.endgame_level', 90);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);

        (new SyncGuildRoster($guild))->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full);
    }

    public function test_force_fanout_constructor_param_triggers_full_dispatch(): void
    {
        config()->set('raiderio.character_resync_ttl', 3600);
        config()->set('blizzard.endgame_level', 90);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);

        // forceFanout=true is the only switch that activates the Full path —
        // used by the seeder so routine guild syncs don't accidentally
        // cascade Full per-member.
        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full && $job->name === 'Alpha');
    }

    public function test_sub_endgame_member_gets_no_dispatch_at_all(): void
    {
        config()->set('raiderio.character_resync_ttl', 3600);
        config()->set('blizzard.endgame_level', 90);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Leveler', 'realm' => 'tarren-mill', 'level' => 89,
            'class_id' => 1, 'race_id' => 1, 'rank' => 2,
        ]);

        (new SyncGuildRoster($guild))->handle();

        // Fan-out is endgame-only: no Shallow, no Full — the member stays a
        // roster row until they hit max level.
        Bus::assertNotDispatched(SyncCharacterData::class, fn ($job) => $job->name === 'Leveler');
    }

    public function test_unique_id_distinguishes_force_fanout_from_auto(): void
    {
        // Otherwise a queued auto-fanout job (proactive sweep) silently
        // dedupes a force-fanout job (user visit / seeder) within the 60s
        // uniqueFor window, dropping per-member Full SyncCharacterData
        // fan-out + teammate crawl.
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);

        $auto = new SyncGuildRoster($guild);
        $force = new SyncGuildRoster($guild, forceFanout: true);

        $this->assertNotSame($auto->uniqueId(), $force->uniqueId());
    }
}
