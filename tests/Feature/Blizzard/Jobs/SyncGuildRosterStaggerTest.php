<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncGuildRosterStaggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config()->set('raiderio.character_resync_ttl', 86400);
        config()->set('blizzard.min_level_for_character_lookup', 70);
        // 60/min → fan-out job i gets an i-second delay: easy math to assert.
        config()->set('blizzard.roster_fanout.jobs_per_minute', 60);
    }

    public function test_fanout_dispatches_get_progressive_delays(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        foreach (['membera', 'memberb', 'memberc'] as $name) {
            GuildMember::factory()->create([
                'guild_id' => $guild->id, 'name' => $name, 'realm' => 'tarren-mill', 'level' => 90,
            ]);
        }

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        $delays = Bus::dispatched(SyncCharacterData::class)
            ->map(fn (SyncCharacterData $j) => (int) ($j->delay ?? 0))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([0, 1, 2], $delays);
    }

    public function test_rate_floor_prevents_division_blowup(): void
    {
        config()->set('blizzard.roster_fanout.jobs_per_minute', 0);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'solo', 'realm' => 'tarren-mill', 'level' => 90,
        ]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(SyncCharacterData::class); // no DivisionByZeroError
    }
}
