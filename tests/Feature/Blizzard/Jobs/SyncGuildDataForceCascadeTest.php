<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SyncGuildDataForceCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_force_cascade_constructor_param_defaults_false(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $this->assertFalse($job->forceCascade);
    }

    public function test_force_cascade_can_be_set_true(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo', forceCascade: true);
        $this->assertTrue($job->forceCascade);
    }

    public function test_unique_id_distinguishes_force_mode_from_auto_mode(): void
    {
        // Otherwise a queued auto-mode job (proactive sweep) silently dedupes
        // a force-mode job (user visit / seeder) within the 60s uniqueFor
        // window, dropping the per-member Full fan-out + teammate crawl.
        $auto = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $cascade = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo', forceCascade: true);
        $rosterForce = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo', forceRosterFanout: true);

        $this->assertNotSame($auto->uniqueId(), $cascade->uniqueId());
        $this->assertNotSame($auto->uniqueId(), $rosterForce->uniqueId());

        // Both force flags collapse onto a single "force" segment so the
        // user-visit cascade and the seeder share one slot (they would
        // produce identical work — fanning out per-member Full).
        $this->assertSame($cascade->uniqueId(), $rosterForce->uniqueId());
    }

    public function test_unique_id_matches_for_same_mode(): void
    {
        $a = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $b = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');

        $this->assertSame($a->uniqueId(), $b->uniqueId());
    }

    public function test_dispatches_sync_guild_roster_with_force_fanout_when_force_cascade_true(): void
    {
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo', forceCascade: true))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === true);
    }

    public function test_dispatches_sync_guild_roster_without_force_fanout_when_force_cascade_false(): void
    {
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === false);
    }

    private function fakeBlizzardGuildEndpoints(): void
    {
        // Important: more-specific roster pattern must come before the looser
        // guild-profile pattern (Laravel's Http::fake matches in order).
        Http::fake([
            '*/data/wow/guild/*/echo/roster*' => Http::response(['members' => []]),
            '*/data/wow/guild/*/echo*' => Http::response([
                'name' => 'Echo',
                'faction' => ['type' => 'HORDE'],
                'achievement_points' => 0,
                'member_count' => 0,
                'created_timestamp' => 0,
                'realm' => ['name' => 'Tarren Mill', 'slug' => 'tarren-mill'],
            ]),
        ]);
    }
}
