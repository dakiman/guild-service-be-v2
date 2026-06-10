<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use Tests\TestCase;

class SyncCharacterDataForceTeammateCrawlTest extends TestCase
{
    public function test_constructor_default_force_teammate_crawl_is_false(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'Test',
            depth: SyncDepth::Full,
        );

        $this->assertFalse($job->forceTeammateCrawl);
    }

    public function test_constructor_accepts_force_teammate_crawl_true(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'Test',
            depth: SyncDepth::Full,
            forceTeammateCrawl: true,
        );

        $this->assertTrue($job->forceTeammateCrawl);
    }

    public function test_force_teammate_crawl_param_survives_serialization(): void
    {
        // The readonly param must survive queue serialize/unserialize round-trip.
        $original = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'Test',
            depth: SyncDepth::Full,
            forceTeammateCrawl: true,
        );

        $serialized = serialize($original);
        $restored = unserialize($serialized);

        $this->assertTrue($restored->forceTeammateCrawl);
    }

    public function test_unique_id_distinguishes_force_teammate_crawl_from_auto(): void
    {
        // Otherwise a queued non-crawl Full job (proactive sweep) silently
        // dedupes a forceTeammateCrawl=true job within the 60s uniqueFor
        // window, dropping the crawl override.
        $auto = new SyncCharacterData('eu', 'tarren-mill', 'Test', SyncDepth::Full);
        $force = new SyncCharacterData('eu', 'tarren-mill', 'Test', SyncDepth::Full, forceTeammateCrawl: true);

        $this->assertNotSame($auto->uniqueId(), $force->uniqueId());
    }
}
