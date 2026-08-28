<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientActiveRaidsTest extends TestCase
{
    public function test_active_raid_slugs_keeps_only_raids_whose_window_is_still_open(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');

        Http::fake([
            'raider.io/api/v1/raiding/static-data*' => Http::response(['raids' => [
                // Ended for every region → not accepted by /raiding/raid-rankings anymore.
                ['slug' => 'tier-mn-1', 'ends' => ['us' => '2026-08-18T15:00:00Z', 'eu' => '2026-08-19T04:00:00Z']],
                // Still open in one region → active.
                ['slug' => 'the-venomous-abyss', 'ends' => ['us' => '2030-01-01T00:00:00Z', 'eu' => '2030-01-01T00:00:00Z']],
                ['slug' => 'the-tidebound-grotto', 'ends' => ['us' => '2026-08-01T00:00:00Z', 'eu' => '2030-01-01T00:00:00Z']],
                // No window published → assume active rather than hide it.
                ['slug' => 'unscheduled'],
                ['name' => 'no slug'],
            ]], 200),
        ]);

        $slugs = app(RaiderIOClient::class)->activeRaidSlugs(11);

        $this->assertSame(['the-venomous-abyss', 'the-tidebound-grotto', 'unscheduled'], $slugs);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'expansion_id=11'));
    }
}
