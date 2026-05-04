<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RaceFaction;
use PHPUnit\Framework\TestCase;

class RaceFactionTest extends TestCase
{
    public function test_alliance_races_resolve_to_alliance(): void
    {
        $allianceRaceIds = [1, 3, 4, 7, 11, 22, 25, 29, 30, 32, 34, 37, 52, 85];
        foreach ($allianceRaceIds as $id) {
            $this->assertSame('Alliance', RaceFaction::for($id), "race_id {$id} should be Alliance");
        }
    }

    public function test_horde_races_resolve_to_horde(): void
    {
        $hordeRaceIds = [2, 5, 6, 8, 9, 10, 26, 27, 28, 31, 35, 36, 70, 84];
        foreach ($hordeRaceIds as $id) {
            $this->assertSame('Horde', RaceFaction::for($id), "race_id {$id} should be Horde");
        }
    }

    public function test_neutral_pandaren_resolves_to_null(): void
    {
        $this->assertNull(RaceFaction::for(24));
    }

    public function test_unknown_race_id_resolves_to_null(): void
    {
        $this->assertNull(RaceFaction::for(9999));
    }
}
