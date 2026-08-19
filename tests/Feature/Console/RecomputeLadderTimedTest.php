<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\LadderRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeLadderTimedTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(int $dungeonId, int $duration, ?bool $timed, string $hash): LadderRun
    {
        return LadderRun::create([
            'period_id' => 1002, 'region' => 'eu', 'dungeon_id' => $dungeonId,
            'keystone_level' => 15, 'duration' => $duration,
            'completed_timestamp' => 1754300000000, 'is_completed_on_time' => $timed,
            'comp_signature' => null, 'run_hash' => sha1($hash),
        ]);
    }

    public function test_recomputes_only_null_rows_from_current_timers(): void
    {
        GameDataMythicKeystoneDungeon::create([
            'id' => 504, 'name' => 'Skyreach',
            'keystone_upgrades' => [['upgrade_level' => 1, 'qualifying_duration' => 1800000]],
        ]);
        $fast = $this->makeRun(504, 1650000, null, 'fast');
        $slow = $this->makeRun(504, 1900000, null, 'slow');
        $frozen = $this->makeRun(504, 1900000, true, 'frozen'); // already judged — untouched
        $orphan = $this->makeRun(999, 1650000, null, 'orphan'); // no dungeon row — stays null

        $this->artisan('ladder:recompute-timed')->assertExitCode(0);

        $this->assertTrue($fast->fresh()->is_completed_on_time);
        $this->assertFalse($slow->fresh()->is_completed_on_time);
        $this->assertTrue($frozen->fresh()->is_completed_on_time);
        $this->assertNull($orphan->fresh()->is_completed_on_time);
    }

    public function test_dry_run_changes_nothing(): void
    {
        GameDataMythicKeystoneDungeon::create([
            'id' => 504, 'name' => 'Skyreach',
            'keystone_upgrades' => [['upgrade_level' => 1, 'qualifying_duration' => 1800000]],
        ]);
        $row = $this->makeRun(504, 1650000, null, 'dry');

        $this->artisan('ladder:recompute-timed', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($row->fresh()->is_completed_on_time);
    }
}
