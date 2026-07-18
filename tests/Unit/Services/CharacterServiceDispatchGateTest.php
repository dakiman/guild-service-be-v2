<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Services\CharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sub-endgame characters must never be dispatched a Full sync from the lookup
 * lane: their null slice timestamps would otherwise read "stale" forever and
 * burn a 9-slice Blizzard fan-out on every staleness window.
 */
class CharacterServiceDispatchGateTest extends TestCase
{
    use RefreshDatabase;

    private function lookup(): ?Character
    {
        return app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'cirna');
    }

    private function makeCharacter(int $level): Character
    {
        return Character::factory()->create([
            'name' => 'cirna',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'level' => $level,
        ]);
    }

    public function test_submax_with_stale_profile_dispatches_standard_never_full(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-01 12:00:00');

        $this->makeCharacter(89);

        // 8 days later: profile stale (7d threshold), slice timestamps all null.
        Carbon::setTestNow(now()->addDays(8));
        $this->lookup();

        Queue::assertPushed(SyncCharacterData::class, 1);
        Queue::assertPushed(SyncCharacterData::class, fn (SyncCharacterData $job) => $job->depth === SyncDepth::Standard);
    }

    public function test_submax_with_fresh_profile_dispatches_nothing(): void
    {
        Queue::fake();

        $this->makeCharacter(89);
        $this->lookup();

        Queue::assertNothingPushed();
    }

    public function test_submax_force_refresh_caps_at_standard(): void
    {
        Queue::fake();

        $this->makeCharacter(89);
        app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'cirna', forceRefresh: true);

        Queue::assertPushed(SyncCharacterData::class, 1);
        Queue::assertPushed(SyncCharacterData::class, fn (SyncCharacterData $job) => $job->depth === SyncDepth::Standard
            && $job->refreshNonce !== null);
    }

    public function test_endgame_all_fresh_force_refresh_dispatches_full_with_nonce(): void
    {
        // Force-refresh must escalate to Full even when nothing reads stale —
        // that's the whole point of "force". The nonce is what lets this
        // dispatch survive ShouldBeUnique dedupe against a concurrent/recent
        // regular sync of the same character.
        Queue::fake();

        $character = $this->makeCharacter(90);
        $now = now();
        $character->forceFill([
            'mythics_synced_at' => $now,
            'pvp_synced_at' => $now,
            'professions_synced_at' => $now,
            'raids_synced_at' => $now,
            'stats_synced_at' => $now,
            'titles_synced_at' => $now,
            'reputations_synced_at' => $now,
            'collections_synced_at' => $now,
            'achievements_synced_at' => $now,
        ])->save();

        app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'cirna', forceRefresh: true);

        Queue::assertPushed(SyncCharacterData::class, 1);
        Queue::assertPushed(SyncCharacterData::class, fn (SyncCharacterData $job) => $job->depth === SyncDepth::Full
            && $job->refreshNonce !== null);
    }

    public function test_endgame_with_stale_slice_dispatches_stale_only(): void
    {
        // StaleOnly (was Full): the job re-consults is*Stale() at execution
        // time and only re-syncs what's actually stale then, so a single
        // stale slice no longer forces a 9-slice fan-out from the view lane.
        Queue::fake();
        Carbon::setTestNow('2026-06-01 12:00:00');

        $character = $this->makeCharacter(90);
        $character->forceFill([
            'mythics_synced_at' => now()->subDays(8),
            'pvp_synced_at' => now(),
            'professions_synced_at' => now(),
            'raids_synced_at' => now(),
            'stats_synced_at' => now(),
            'titles_synced_at' => now(),
            'reputations_synced_at' => now(),
            'collections_synced_at' => now(),
            'achievements_synced_at' => now(),
        ])->save();

        $this->lookup();

        Queue::assertPushed(SyncCharacterData::class, fn (SyncCharacterData $job) => $job->depth === SyncDepth::StaleOnly);
    }
}
