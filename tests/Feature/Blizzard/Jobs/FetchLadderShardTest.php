<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\GameDataClientFactory;
use App\Blizzard\Jobs\FetchLadderShard;
use App\Blizzard\Mappers\BlizzardLadderMapper;
use App\Blizzard\Services\LadderRunPersister;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\LadderRun;
use App\Models\LadderRunMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchLadderShardTest extends TestCase
{
    use RefreshDatabase;

    private function fakeLeaderboard(?array $payload): void
    {
        $client = $this->createMock(BlizzardGameDataClient::class);
        $client->method('getMythicLeaderboard')->willReturn($payload);
        $factory = $this->createMock(GameDataClientFactory::class);
        $factory->method('forRegion')->willReturn($client);
        $this->app->instance(GameDataClientFactory::class, $factory);
    }

    private function group(int $profileIdBase): array
    {
        $member = fn (int $i, int $spec) => [
            'profile' => ['id' => $profileIdBase + $i, 'name' => "Char{$i}", 'realm' => ['id' => 1596, 'slug' => 'the-maelstrom']],
            'faction' => ['type' => 'HORDE'],
            'specialization' => ['id' => $spec],
        ];

        return [
            'duration' => 1650000, 'completed_timestamp' => 1754300000000 + $profileIdBase, 'keystone_level' => 15,
            'members' => [$member(1, 268), $member(2, 65), $member(3, 102), $member(4, 253), $member(5, 577)],
        ];
    }

    public function test_persists_runs_and_members_once(): void
    {
        GameDataMythicKeystoneDungeon::create([
            'id' => 504, 'name' => 'Skyreach',
            'keystone_upgrades' => [['upgrade_level' => 1, 'qualifying_duration' => 1800000]],
        ]);
        $this->fakeLeaderboard(['leading_groups' => [$this->group(100), $this->group(200)], 'keystone_affixes' => []]);

        (new FetchLadderShard('eu', 509, 504, 1002))->handle(
            app(GameDataClientFactory::class),
            app(BlizzardLadderMapper::class),
            app(LadderRunPersister::class),
        );

        $this->assertSame(2, LadderRun::count());
        $this->assertSame(10, LadderRunMember::count());
        $this->assertTrue(LadderRun::first()->is_completed_on_time);

        // Second shard (another connected realm) returning the same runs → all deduped.
        (new FetchLadderShard('eu', 1305, 504, 1002))->handle(
            app(GameDataClientFactory::class),
            app(BlizzardLadderMapper::class),
            app(LadderRunPersister::class),
        );
        $this->assertSame(2, LadderRun::count());
        $this->assertSame(10, LadderRunMember::count());
    }

    public function test_404_shard_is_a_silent_noop(): void
    {
        $this->fakeLeaderboard(null);

        (new FetchLadderShard('eu', 509, 504, 1002))->handle(
            app(GameDataClientFactory::class),
            app(BlizzardLadderMapper::class),
            app(LadderRunPersister::class),
        );

        $this->assertSame(0, LadderRun::count());
    }
}
