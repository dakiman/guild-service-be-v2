<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\LadderRun;
use App\Models\LadderRunMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LadderRunTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $overrides = []): LadderRun
    {
        return LadderRun::create(array_merge([
            'period_id' => 1002,
            'region' => 'eu',
            'dungeon_id' => 504,
            'keystone_level' => 15,
            'duration' => 1650000,
            'completed_timestamp' => 1754300000000,
            'is_completed_on_time' => true,
            'affixes' => [9, 10],
            'comp_signature' => '268:65:102,253,577',
            'run_hash' => sha1('run-a'),
        ], $overrides));
    }

    public function test_run_hash_is_unique(): void
    {
        $this->makeRun();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeRun(['keystone_level' => 16]);
    }

    public function test_members_relation_and_casts(): void
    {
        $run = $this->makeRun();
        LadderRunMember::create([
            'ladder_run_id' => $run->id,
            'profile_id' => 123456,
            'name' => 'Melaniya',
            'realm_slug' => 'the-maelstrom',
            'realm_id' => 1596,
            'faction' => 'HORDE',
            'spec_id' => 577,
        ]);

        $this->assertSame([9, 10], $run->fresh()->affixes);
        $this->assertCount(1, $run->memberEntries);
        $this->assertSame(577, $run->memberEntries->first()->spec_id);
    }
}
