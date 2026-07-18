<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use Tests\TestCase;

/**
 * Lock-release compat regression guard: Laravel calls uniqueId() a SECOND
 * time on the unserialized job instance to release the ShouldBeUnique lock
 * after handle() runs. A null/unset $refreshNonce must reproduce the exact
 * legacy key string, or the lock release silently no-ops (mismatched key)
 * and the lock leaks until uniqueFor expires.
 */
class SyncCharacterDataUniqueIdTest extends TestCase
{
    public function test_null_nonce_reproduces_exact_legacy_key(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'cirna',
            depth: SyncDepth::Standard,
            origin: SyncOrigin::UserLookup,
        );

        $this->assertSame('sync-char:eu:the-maelstrom:cirna:standard:auto', $job->uniqueId());
    }

    public function test_nonce_appends_suffix_to_unique_id(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'cirna',
            depth: SyncDepth::Full,
            origin: SyncOrigin::UserLookup,
            refreshNonce: 'abc123',
        );

        $this->assertSame('sync-char:eu:the-maelstrom:cirna:full:auto:abc123', $job->uniqueId());
    }

    public function test_force_teammate_crawl_mode_segment_unaffected_by_nonce(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'cirna',
            depth: SyncDepth::Full,
            forceTeammateCrawl: true,
            origin: SyncOrigin::UserLookup,
            refreshNonce: 'xyz789',
        );

        $this->assertSame('sync-char:eu:the-maelstrom:cirna:full:force:xyz789', $job->uniqueId());
    }

    /**
     * The real failure mode this guards against: an OLD-shape queue payload
     * (queued before $refreshNonce existed) unserializes with the property
     * left uninitialized rather than null — a plain `=== null` check would
     * throw "must not be accessed before initialization" instead of falling
     * through to the legacy key.
     */
    public function test_uninitialized_nonce_never_fatals_and_reproduces_legacy_key(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'the-maelstrom',
            name: 'cirna',
            depth: SyncDepth::Standard,
            origin: SyncOrigin::UserLookup,
            refreshNonce: 'will-be-erased',
        );

        unset($job->refreshNonce);

        $this->assertSame('sync-char:eu:the-maelstrom:cirna:standard:auto', $job->uniqueId());
    }
}
