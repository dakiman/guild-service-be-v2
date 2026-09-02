<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\GameDataConnectedRealm;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataPeriod;
use App\Models\GameDataSeason;
use App\Models\LadderRun;
use App\Models\RealmRunBoard;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealmRunBoardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['blizzard.mplus_leaderboard.regions' => ['eu']]);
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'MN Season 2', 'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11, 'is_current' => true, 'started_at' => '2026-08-22 00:00:00',
        ]);
        Seasons::clearCache();
        GameDataPeriod::create(['period_id' => 1078, 'region' => 'eu', 'start_at' => now()->subDay(), 'end_at' => now()->addDays(6)]);
        GameDataConnectedRealm::create(['region' => 'eu', 'connected_realm_id' => 1403, 'realm_slugs' => ['draenor']]);
        GameDataConnectedRealm::create(['region' => 'eu', 'connected_realm_id' => 1084, 'realm_slugs' => ['tarren-mill', 'dentarg']]);
        GameDataMythicKeystoneDungeon::create(['id' => 504, 'name' => 'Darkflame Cleft']);
    }

    /** @param list<array{name:string, realm:string, spec:int}> $members */
    private function makeRun(int $level, int $duration, array $members, bool $timed = true, int $period = 1078): LadderRun
    {
        $run = LadderRun::create([
            'period_id' => $period, 'region' => 'eu', 'dungeon_id' => 504, 'keystone_level' => $level,
            'duration' => $duration, 'completed_timestamp' => 1756700000000, 'is_completed_on_time' => $timed,
            'run_hash' => sha1("{$level}-{$duration}-{$period}-".json_encode($members)),
        ]);
        foreach ($members as $m) {
            $run->memberEntries()->create(['name' => $m['name'], 'realm_slug' => $m['realm'], 'spec_id' => $m['spec']]);
        }

        return $run;
    }

    public function test_boards_group_runs_by_member_connected_realm_in_level_then_duration_order(): void
    {
        $slow = $this->makeRun(15, 1_700_000, [['name' => 'Ayla', 'realm' => 'draenor', 'spec' => 267]]);
        $fast = $this->makeRun(15, 1_600_000, [['name' => 'Bex', 'realm' => 'draenor', 'spec' => 581]]);
        $high = $this->makeRun(17, 1_900_000, [['name' => 'Cyn', 'realm' => 'dentarg', 'spec' => 105], ['name' => 'Dov', 'realm' => 'draenor', 'spec' => 62]]);
        $this->makeRun(20, 1_000_000, [['name' => 'Nope', 'realm' => 'draenor', 'spec' => 62]], timed: false);
        $this->makeRun(20, 1_000_000, [['name' => 'Old', 'realm' => 'draenor', 'spec' => 62]], period: 1077);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $draenor = RealmRunBoard::where('region', 'eu')->where('connected_realm_id', 1403)->where('period_id', 1078)->firstOrFail();
        $this->assertSame([$high->id, $fast->id, $slow->id], array_column($draenor->payload, 'id'));

        $first = $draenor->payload[0];
        $this->assertSame('Darkflame Cleft', $first['dungeon_name']);
        $this->assertSame(17, $first['keystone_level']);
        $this->assertSame([], $first['affixes']);
        $this->assertSame('cyn', $first['members'][0]['name']);
        $this->assertSame('dentarg', $first['members'][0]['realm']);
        $this->assertSame('eu', $first['members'][0]['region']);
        $this->assertSame(11, $first['members'][0]['class_id']); // Restoration Druid → Druid
        $this->assertNull($first['members'][0]['ilvl']);

        // The cross-realm run also appears on tarren-mill/dentarg's group (1084).
        $tm = RealmRunBoard::where('connected_realm_id', 1084)->firstOrFail();
        $this->assertSame([$high->id], array_column($tm->payload, 'id'));
    }

    public function test_board_is_capped_at_twenty_and_rerun_replaces(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeRun(10 + $i, 1_500_000, [['name' => "p{$i}", 'realm' => 'draenor', 'spec' => 62]]);
        }

        $this->artisan('ranks:materialize')->assertExitCode(0);
        $this->artisan('ranks:materialize')->assertExitCode(0);

        $board = RealmRunBoard::where('connected_realm_id', 1403)->get();
        $this->assertCount(1, $board);
        $this->assertCount(20, $board->first()->payload);
        $this->assertSame(34, $board->first()->payload[0]['keystone_level']);
    }

    public function test_no_current_period_skips_boards_but_still_ranks(): void
    {
        GameDataPeriod::query()->delete();
        $this->makeRun(15, 1_600_000, [['name' => 'Bex', 'realm' => 'draenor', 'spec' => 581]]);

        $this->artisan('ranks:materialize')->assertExitCode(0);

        $this->assertSame(0, RealmRunBoard::count());
    }
}
