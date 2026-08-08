<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\LadderRun;
use App\Models\LadderRunMember;
use App\Models\MetaSnapshot;
use App\Services\MetaStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $hashSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'blizzard.mplus_leaderboard.regions' => ['eu', 'us'],
            'blizzard.mplus_leaderboard.brackets' => [0, 12],
            'blizzard.mplus_leaderboard.comp_min_sample' => 2,
        ]);
        GameDataMythicKeystoneDungeon::create([
            'id' => 504, 'name' => 'Skyreach',
            'keystone_upgrades' => [['upgrade_level' => 1, 'qualifying_duration' => 1800000]],
        ]);
        GameDataMythicKeystoneDungeon::create([
            'id' => 505, 'name' => 'Pit of Saron',
            'keystone_upgrades' => [['upgrade_level' => 1, 'qualifying_duration' => 2000000]],
        ]);
    }

    private function makeRun(int $dungeonId, int $level, bool $timed, string $region = 'eu', array $specs = [268, 65, 102, 253, 577]): LadderRun
    {
        // Derive the signature the same way the mapper would (fixture spec ids:
        // tanks 268/104, healers 65/105, rest dps).
        $tanks = array_values(array_intersect($specs, [268, 104]));
        $healers = array_values(array_intersect($specs, [65, 105]));
        $dps = array_values(array_diff($specs, $tanks, $healers));
        sort($dps);

        $run = LadderRun::create([
            'period_id' => 1002, 'region' => $region, 'dungeon_id' => $dungeonId,
            'keystone_level' => $level, 'duration' => $timed ? 1650000 : 1900000,
            'completed_timestamp' => 1754300000000 + $this->hashSeq,
            'is_completed_on_time' => $timed, 'affixes' => [9],
            'comp_signature' => $tanks[0].':'.$healers[0].':'.implode(',', $dps),
            'run_hash' => sha1('fixture-'.$this->hashSeq++),
        ]);
        foreach ($specs as $spec) {
            LadderRunMember::create([
                'ladder_run_id' => $run->id, 'profile_id' => $this->hashSeq * 10 + $spec,
                'name' => "C{$spec}", 'realm_slug' => 'r', 'realm_id' => 1, 'faction' => 'HORDE', 'spec_id' => $spec,
            ]);
        }

        return $run;
    }

    public function test_compute_specs_shares_timed_rates_and_brackets(): void
    {
        $this->makeRun(504, 15, true);                                   // eu, in 12+ bracket
        $this->makeRun(504, 10, false);                                  // eu, below 12
        $this->makeRun(504, 15, true, 'us', [104, 105, 102, 253, 577]);  // us run, different tank/healer

        $service = app(MetaStatsService::class);

        $all = $service->computeSpecs(1002, 'all');
        $this->assertSame(3, $all['brackets']['all']['total_runs']);
        $tanks = collect($all['brackets']['all']['roles']['tank']);
        $this->assertSame(2, $tanks->firstWhere('spec_id', 268)['count']);
        $this->assertEqualsWithDelta(0.6667, $tanks->firstWhere('spec_id', 268)['share'], 0.001);
        $this->assertEqualsWithDelta(0.5, $tanks->firstWhere('spec_id', 268)['timed_rate'], 0.001);

        $this->assertSame(2, $all['brackets']['12']['total_runs']);

        $eu = $service->computeSpecs(1002, 'eu');
        $this->assertSame(2, $eu['brackets']['all']['total_runs']);
        $this->assertNull(collect($eu['brackets']['all']['roles']['tank'])->firstWhere('spec_id', 104));
    }

    public function test_compute_dungeons_report_and_pick(): void
    {
        $this->makeRun(504, 15, true);
        $this->makeRun(504, 16, true);
        $this->makeRun(505, 15, false);

        $payload = app(MetaStatsService::class)->computeDungeons(1002, 'all');

        $sky = collect($payload['dungeons'])->firstWhere('dungeon_id', 504);
        $this->assertSame('Skyreach', $sky['name']);
        $this->assertSame(2, $sky['runs']);
        $this->assertSame(1.0, $sky['timed_rate']);
        $this->assertSame(16, $sky['highest_key']);
        $this->assertSame(1800000, $sky['timer_ms']);
        $this->assertSame(150000, $sky['avg_margin_ms']); // 1800000 - 1650000
        $this->assertSame(504, $payload['dungeon_of_the_week']);
    }

    public function test_compute_comps_respects_min_sample_and_parses_signature(): void
    {
        $this->makeRun(504, 15, true);
        $this->makeRun(504, 16, true);
        $this->makeRun(505, 15, false, 'eu', [104, 105, 102, 253, 577]); // singleton comp, below min_sample=2

        $payload = app(MetaStatsService::class)->computeComps(1002, 'all');

        $this->assertCount(1, $payload['comps']);
        $comp = $payload['comps'][0];
        $this->assertSame(268, $comp['tank_spec_id']);
        $this->assertSame(65, $comp['healer_spec_id']);
        $this->assertSame([102, 253, 577], $comp['dps_spec_ids']);
        $this->assertSame(2, $comp['count']);
        $this->assertSame(1.0, $comp['timed_rate']);
        $this->assertCount(1, $payload['pairings']);
        $this->assertSame(2, $payload['min_sample']);
    }

    public function test_warm_writes_snapshot_rows_for_all_scopes(): void
    {
        $this->makeRun(504, 15, true);

        app(MetaStatsService::class)->warm(1002);

        // scopes: all, eu, us × sections: specs, dungeons, comps
        $this->assertSame(9, MetaSnapshot::count());

        // idempotent — rewarming overwrites, not duplicates
        app(MetaStatsService::class)->warm(1002);
        $this->assertSame(9, MetaSnapshot::count());
    }
}
