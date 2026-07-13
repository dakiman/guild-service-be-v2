<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncOrigin;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillGuildShellsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_discovery_sync_for_never_synced_shells_only(): void
    {
        Queue::fake();
        $shell = Guild::factory()->create(['roster_synced_at' => null]);
        Guild::factory()->create(['roster_synced_at' => now()]);

        $this->artisan('guilds:backfill-shells')
            ->expectsOutputToContain('Dispatched 1')
            ->assertExitCode(0);

        Queue::assertPushed(SyncGuildData::class, 1);
        Queue::assertPushed(SyncGuildData::class, fn (SyncGuildData $job) => $job->name === $shell->name
            && $job->origin === SyncOrigin::Discovery
            && $job->forceRosterFanout === false);
    }

    public function test_limit_caps_dispatches(): void
    {
        Queue::fake();
        Guild::factory()->count(3)->create(['roster_synced_at' => null]);

        $this->artisan('guilds:backfill-shells', ['--limit' => 2])->assertExitCode(0);

        Queue::assertPushed(SyncGuildData::class, 2);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Queue::fake();
        Guild::factory()->count(2)->create(['roster_synced_at' => null]);

        $this->artisan('guilds:backfill-shells', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] would dispatch 2')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
