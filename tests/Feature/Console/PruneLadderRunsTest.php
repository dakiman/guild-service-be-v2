<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\LadderRun;
use App\Models\LadderRunMember;
use App\Models\MetaSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneLadderRunsTest extends TestCase
{
    use RefreshDatabase;

    private function seedPeriod(int $periodId): void
    {
        $run = LadderRun::create([
            'period_id' => $periodId, 'region' => 'eu', 'dungeon_id' => 504,
            'keystone_level' => 15, 'duration' => 1650000,
            'completed_timestamp' => 1754300000000 + $periodId, 'is_completed_on_time' => true,
            'comp_signature' => null, 'run_hash' => sha1("prune-{$periodId}"),
        ]);
        LadderRunMember::create([
            'ladder_run_id' => $run->id, 'profile_id' => $periodId,
            'name' => 'c', 'realm_slug' => 'r', 'realm_id' => 1, 'faction' => 'HORDE', 'spec_id' => 268,
        ]);
        MetaSnapshot::create([
            'period_id' => $periodId, 'region' => 'eu', 'section' => 'specs',
            'payload' => ['brackets' => []], 'computed_at' => now(),
        ]);
    }

    public function test_prunes_periods_beyond_keep_but_never_snapshots(): void
    {
        foreach ([1000, 1001, 1002, 1003] as $p) {
            $this->seedPeriod($p);
        }

        $this->artisan('ladder:prune', ['--keep' => 2])->assertExitCode(0);

        $this->assertSame([1002, 1003], LadderRun::query()->orderBy('period_id')->pluck('period_id')->unique()->values()->all());
        $this->assertSame(2, LadderRunMember::count());
        $this->assertSame(4, MetaSnapshot::count()); // history lives here — untouched
    }

    public function test_dry_run_deletes_nothing(): void
    {
        foreach ([1000, 1001, 1002] as $p) {
            $this->seedPeriod($p);
        }

        $this->artisan('ladder:prune', ['--keep' => 1, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(3, LadderRun::count());
    }

    public function test_fewer_periods_than_keep_is_a_noop(): void
    {
        $this->seedPeriod(1002);

        $this->artisan('ladder:prune')->assertExitCode(0);

        $this->assertSame(1, LadderRun::count());
    }
}
