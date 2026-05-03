<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\SeededRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOSeedCommandRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);
    }

    public function test_phase_runs_dispatches_full_per_member_and_records_ledger(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'runs',
            '--limit' => 1,
            '--regions' => 'eu',
        ])->assertSuccessful();

        // Fixture has 3 runs × 5 members each = 15 dispatches.
        Bus::assertDispatched(SyncCharacterData::class, 15);

        $this->assertSame(3, SeededRun::count());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1001)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1002)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1003)->exists());
    }

    public function test_phase_runs_dry_run_dispatches_nothing(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'runs',
            '--limit' => 1,
            '--regions' => 'eu',
            '--dry-run' => true,
        ])->assertSuccessful();

        Bus::assertNothingDispatched();
        // Dry-run does NOT mutate the ledger — keeps the command idempotent.
        $this->assertSame(0, SeededRun::count());
    }
}
