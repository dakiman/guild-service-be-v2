<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1.2: DungeonRun persistence must key on the full DB unique index
 * (season, dungeon_id, completed_timestamp, duration). updateOrCreate matched
 * only the first three, so it disagreed with `uq_dungeon_run`: two runs that
 * legitimately differ only by duration collapsed onto one row, and a
 * check-then-insert race between concurrent teammate syncs raised 23505.
 */
class DungeonRunUpsertTest extends TestCase
{
    use RefreshDatabase;

    private function attrs(array $overrides = []): array
    {
        return array_merge([
            'season' => 14,
            'dungeon_id' => 500,
            'completed_timestamp' => 1700000000000,
            'duration' => 1_800_000,
            'dungeon_name' => 'Ara-Kara, City of Echoes',
            'keystone_level' => 12,
            'is_completed_on_time' => true,
            'affixes' => [['id' => 9, 'name' => 'Tyrannical']],
        ], $overrides);
    }

    public function test_keeps_runs_that_differ_only_by_duration(): void
    {
        DungeonRun::upsertRun($this->attrs(['duration' => 1_800_000]));
        DungeonRun::upsertRun($this->attrs(['duration' => 1_900_000, 'is_completed_on_time' => false]));

        $this->assertSame(2, DungeonRun::count());
    }

    public function test_is_idempotent_on_the_full_key_and_round_trips_affixes(): void
    {
        $first = DungeonRun::upsertRun($this->attrs(['keystone_level' => 12]));

        // Same full key, changed payload — must update the existing row, not insert.
        $second = DungeonRun::upsertRun($this->attrs(['keystone_level' => 15]));

        $this->assertSame(1, DungeonRun::count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(15, $second->keystone_level);
        // affixes survives the round trip through the JSON cast.
        $this->assertSame([['id' => 9, 'name' => 'Tyrannical']], $second->affixes);
    }
}
