<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Enums\SyncOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SyncGuildDataRosterDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_defaults_to_user_lookup_origin_on_user_sync_lane(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');

        $this->assertSame(SyncOrigin::UserLookup, $job->origin);
        $this->assertSame('blizzard-user-sync', $job->queue);
    }

    public function test_discovery_origin_routes_to_background_lane(): void
    {
        $job = new SyncGuildData(
            region: 'eu', realm: 'tarren-mill', name: 'echo',
            origin: SyncOrigin::Discovery,
        );

        $this->assertSame('blizzard-background', $job->queue);
    }

    public function test_tags_carry_origin_and_guild_identity(): void
    {
        $job = new SyncGuildData(
            region: 'eu', realm: 'tarren-mill', name: 'echo',
            origin: SyncOrigin::Discovery,
        );

        $this->assertSame(['origin:discovery', 'guild:eu:tarren-mill:echo'], $job->tags());
    }

    public function test_does_not_dispatch_roster_job_by_default(): void
    {
        // The 2026-07-12 incident: every guild sync fanning out roster work
        // flooded the queues. Default is now profile + roster rows ONLY.
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertNotDispatched(SyncGuildRoster::class);
    }

    public function test_dispatches_roster_job_with_force_fanout_when_asked(): void
    {
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo', forceRosterFanout: true))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === true);
    }

    public function test_unique_id_distinguishes_force_mode_from_auto_mode(): void
    {
        $auto = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $force = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo', forceRosterFanout: true);

        $this->assertNotSame($auto->uniqueId(), $force->uniqueId());
    }

    public function test_unique_id_matches_for_same_mode(): void
    {
        $a = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $b = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');

        $this->assertSame($a->uniqueId(), $b->uniqueId());
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
