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

    public function test_dispatches_full_sync_for_each_member_when_flag_enabled(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', true);
        config()->set('raiderio.character_resync_ttl', 3600);
        // SyncGuildRoster reads min level from blizzard config; ensure factory level >= min.
        config()->set('blizzard.min_level_for_character_lookup', 70);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Beta', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 2, 'race_id' => 1, 'rank' => 1,
        ]);

        (new SyncGuildRoster($guild))->handle();

        Bus::assertDispatched(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full && $job->name === 'Alpha';
        });
        Bus::assertDispatched(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full && $job->name === 'Beta';
        });
    }

    public function test_skips_full_dispatch_when_member_was_recently_updated(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', true);
        config()->set('raiderio.character_resync_ttl', 3600);
        config()->set('blizzard.min_level_for_character_lookup', 70);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Fresh', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);

        // Existing Character row, recently synced.
        Character::factory()->create([
            'name' => 'Fresh',
            'realm' => 'tarren-mill',
            'region' => 'eu',
        ]);
        // Force updated_at to be recent (factory may overwrite to now() automatically — that's fine for this test)
        Character::where(['name' => 'Fresh', 'realm' => 'tarren-mill', 'region' => 'eu'])
            ->update(['updated_at' => now()->subMinutes(5)]);

        (new SyncGuildRoster($guild))->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full && $job->name === 'Fresh'
        );
    }

    public function test_dispatches_full_sync_when_member_is_stale(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', true);
        config()->set('raiderio.character_resync_ttl', 3600);  // 1h TTL
        config()->set('blizzard.min_level_for_character_lookup', 70);

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

        Bus::assertDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full && $job->name === 'Stale'
        );
    }

    public function test_skips_all_full_dispatches_when_flag_disabled(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', false);
        config()->set('blizzard.min_level_for_character_lookup', 70);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);

        (new SyncGuildRoster($guild))->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full);
    }

    public function test_force_fanout_constructor_param_overrides_disabled_config(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', false);
        config()->set('raiderio.character_resync_ttl', 3600);
        config()->set('blizzard.min_level_for_character_lookup', 70);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 90,
            'class_id' => 1, 'race_id' => 1, 'rank' => 0,
        ]);

        // forceFanout=true overrides the config flag — used by the seeder so
        // routine guild syncs don't accidentally cascade Full per-member.
        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full && $job->name === 'Alpha');
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
