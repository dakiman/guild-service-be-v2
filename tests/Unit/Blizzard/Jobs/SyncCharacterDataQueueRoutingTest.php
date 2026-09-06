<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncOrigin;
use PHPUnit\Framework\TestCase;

final class SyncCharacterDataQueueRoutingTest extends TestCase
{
    public function test_default_origin_is_user_lookup_on_user_sync_queue(): void
    {
        $job = new SyncCharacterData('eu', 'silvermoon', 'stargiirll');

        $this->assertSame(SyncOrigin::UserLookup, $job->origin);
        $this->assertSame('blizzard-user-sync', $job->queue);
    }

    public function test_each_origin_routes_to_its_lane(): void
    {
        $expectations = [
            [SyncOrigin::UserLookup, 'blizzard-user-sync'],
            [SyncOrigin::RosterFanout, 'blizzard-roster-sync'],
            [SyncOrigin::TeammateCrawl, 'blizzard-crawl'],
            [SyncOrigin::Proactive, 'blizzard-background'],
        ];

        foreach ($expectations as [$origin, $queue]) {
            $job = new SyncCharacterData('eu', 'silvermoon', 'stargiirll', origin: $origin);
            $this->assertSame($queue, $job->queue, "origin {$origin->value}");
        }
    }

    public function test_crawl_depth_no_longer_influences_routing(): void
    {
        // Regression guard for the flood bug: crawlDepth used to be the
        // routing signal; now only origin is.
        $job = new SyncCharacterData('eu', 'silvermoon', 'stargiirll', crawlDepth: 2);

        $this->assertSame('blizzard-user-sync', $job->queue);
    }

    public function test_tags_expose_origin_and_identity(): void
    {
        $job = new SyncCharacterData('eu', 'silvermoon', 'stargiirll', origin: SyncOrigin::RosterFanout);

        $this->assertContains('origin:roster-fanout', $job->tags());
        $this->assertContains('character:eu:silvermoon:stargiirll', $job->tags());
    }
}
