<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RaidRetention;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_current_expansion_plus_current_season_when_seeded(): void
    {
        $this->seed(GameDataExpansionSeeder::class);

        $this->assertSame('Midnight', RaidRetention::currentExpansionName());
        $this->assertSame(['Midnight', 'Current Season'], RaidRetention::expansions());
    }

    public function test_returns_null_when_expansions_table_is_empty(): void
    {
        $this->assertNull(RaidRetention::currentExpansionName());
        $this->assertNull(RaidRetention::expansions());
    }
}
