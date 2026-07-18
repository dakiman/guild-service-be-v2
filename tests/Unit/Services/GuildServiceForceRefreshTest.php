<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncOrigin;
use App\Models\Guild;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GuildService mirror of CharacterServiceForceRefreshTest /
 * CharacterServiceDispatchGateTest's force-refresh coverage.
 */
class GuildServiceForceRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_force_refresh_dispatches_sync_guild_data_with_nonce_even_when_fresh(): void
    {
        Queue::fake();

        $guild = Guild::factory()->create([
            'name' => 'testguild',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();

        app(GuildService::class)->getByIdentity('eu', 'the-maelstrom', 'testguild', forceRefresh: true);

        Queue::assertPushed(SyncGuildData::class, 1);
        Queue::assertPushed(SyncGuildData::class, fn (SyncGuildData $job) => $job->refreshNonce !== null
            && $job->forceRosterFanout === false);
    }

    public function test_not_found_marker_with_force_refresh_returns_null_and_forgets_marker(): void
    {
        Queue::fake();

        Cache::put('blizzard:not-found:guild:eu:the-maelstrom:ghostguild', true, 60);

        $result = app(GuildService::class)->getByIdentity('eu', 'the-maelstrom', 'ghostguild', forceRefresh: true);

        $this->assertNull($result);
        $this->assertFalse(
            Cache::has('blizzard:not-found:guild:eu:the-maelstrom:ghostguild'),
            'not-found marker must be forgotten on force-refresh',
        );
    }

    public function test_null_nonce_reproduces_exact_legacy_unique_id(): void
    {
        $job = new SyncGuildData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'testguild',
            origin: SyncOrigin::UserLookup,
        );

        $this->assertSame('sync-guild:eu:the-maelstrom:testguild:auto', $job->uniqueId());
    }

    public function test_nonce_appends_suffix_to_unique_id(): void
    {
        $job = new SyncGuildData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'testguild',
            origin: SyncOrigin::UserLookup,
            refreshNonce: 'abc123',
        );

        $this->assertSame('sync-guild:eu:the-maelstrom:testguild:auto:abc123', $job->uniqueId());
    }

    public function test_uninitialized_nonce_never_fatals_and_reproduces_legacy_key(): void
    {
        $job = new SyncGuildData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'testguild',
            origin: SyncOrigin::UserLookup,
            refreshNonce: 'will-be-erased',
        );

        unset($job->refreshNonce);

        $this->assertSame('sync-guild:eu:the-maelstrom:testguild:auto', $job->uniqueId());
    }
}
