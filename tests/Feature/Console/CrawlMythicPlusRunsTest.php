<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\Character;
use App\Services\RaiderIO\Jobs\CrawlCharacterRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CrawlMythicPlusRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config()->set('raiderio.crawl.enabled', true);

        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $this->app->instance(BlizzardGameDataClient::class, $mock);
    }

    public function test_limit_dispatches_highest_rated_characters_first(): void
    {
        // Inserted in ASCENDING rating order — so an unordered LIMIT 2 would pick
        // the two lowest (100, 200). The command must order by rating DESC and
        // pick the two highest (300, 200), skipping 100.
        foreach ([['low', 100], ['mid', 200], ['high', 300]] as [$name, $rating]) {
            Character::factory()->create([
                'name' => $name,
                'realm' => 'tarren-mill',
                'region' => 'eu',
                'game_version' => 'retail',
                'mythic_plus_rating' => $rating,
            ]);
        }

        $this->artisan('raiderio:crawl-runs', ['--limit' => 2])
            ->assertExitCode(0);

        Bus::assertDispatched(
            CrawlCharacterRuns::class,
            fn (CrawlCharacterRuns $j) => $j->name === 'high',
        );
        Bus::assertDispatched(
            CrawlCharacterRuns::class,
            fn (CrawlCharacterRuns $j) => $j->name === 'mid',
        );
        Bus::assertNotDispatched(
            CrawlCharacterRuns::class,
            fn (CrawlCharacterRuns $j) => $j->name === 'low',
        );
        Bus::assertDispatchedTimes(CrawlCharacterRuns::class, 2);
    }
}
