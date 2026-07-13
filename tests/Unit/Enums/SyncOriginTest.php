<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SyncOrigin;
use PHPUnit\Framework\TestCase;

final class SyncOriginTest extends TestCase
{
    public function test_origin_to_queue_map(): void
    {
        $this->assertSame('blizzard-user-sync', SyncOrigin::UserLookup->queue());
        $this->assertSame('blizzard-roster-sync', SyncOrigin::RosterFanout->queue());
        $this->assertSame('blizzard-background', SyncOrigin::TeammateCrawl->queue());
        $this->assertSame('blizzard-background', SyncOrigin::Proactive->queue());
    }

    public function test_discovery_routes_to_background_queue(): void
    {
        $this->assertSame('blizzard-background', SyncOrigin::Discovery->queue());
    }

    public function test_discovery_value_is_stable(): void
    {
        // Serialized into queued payloads and Horizon tags — never change it.
        $this->assertSame('discovery', SyncOrigin::Discovery->value);
    }
}
