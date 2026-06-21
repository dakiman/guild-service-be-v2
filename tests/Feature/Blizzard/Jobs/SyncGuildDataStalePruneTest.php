<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * P1.4: stale-member pruning must compare the full (name, realm) tuple — the
 * unique key is (guild_id, name, realm). Comparing name alone leaves a
 * realm-transferred member as a permanent duplicate.
 */
class SyncGuildDataStalePruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SyncGuildRoster::class]);

        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_realm_transferred_member_is_pruned_not_left_as_duplicate(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'echo']);

        // delta is currently rostered on a different realm (pre-transfer).
        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'name' => 'delta',
            'realm' => 'old-realm',
        ]);

        // New roster: delta has transferred to tarren-mill (same name, new realm).
        $this->fakeRosterWith([
            ['name' => 'Delta', 'realm' => 'Tarren Mill', 'level' => 90, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $deltaRows = GuildMember::where('guild_id', $guild->id)->where('name', 'delta')->get();
        $this->assertCount(1, $deltaRows, 'realm-transferred member must not be left as a duplicate');
        $this->assertSame('tarren-mill', $deltaRows->first()->realm);
    }

    private function fakeRosterWith(array $members): void
    {
        Http::fake([
            '*/data/wow/guild/*/echo/roster*' => Http::response([
                'members' => array_map(fn ($m) => [
                    'character' => [
                        'name' => $m['name'],
                        'realm' => ['name' => $m['realm'], 'slug' => strtolower(str_replace(' ', '-', $m['realm']))],
                        'level' => $m['level'],
                        'playable_class' => ['id' => $m['class_id']],
                        'playable_race' => ['id' => $m['race_id']],
                    ],
                    'rank' => $m['rank'],
                ], $members),
            ]),
            '*/data/wow/guild/*/echo*' => Http::response([
                'name' => 'Echo',
                'faction' => ['type' => 'HORDE'],
                'achievement_points' => 0,
                'member_count' => count($members),
                'created_timestamp' => 0,
                'realm' => ['name' => 'Tarren Mill', 'slug' => 'tarren-mill'],
            ]),
        ]);
    }
}
