<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncGuildData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);
    }

    public function test_phase_guilds_dispatches_sync_guild_data_per_ref(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'guilds',
            '--limit' => 3,
            '--regions' => 'eu',
        ])->assertSuccessful();

        Bus::assertDispatched(SyncGuildData::class, 3);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'guilds',
            '--limit' => 3,
            '--regions' => 'eu',
            '--dry-run' => true,
        ])->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_invalid_phase_returns_failure_exit(): void
    {
        $this->artisan('raiderio:seed', ['--phase' => 'bogus'])
            ->assertFailed();
    }

    public function test_unsupported_phase_characters_returns_not_implemented(): void
    {
        $this->artisan('raiderio:seed', ['--phase' => 'characters'])
            ->expectsOutputToContain('not yet implemented')
            ->assertFailed();
    }
}
