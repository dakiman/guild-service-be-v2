<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\GameDataPeriod;
use App\Models\MetaSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(int $periodId, string $region, string $section, array $payload): void
    {
        MetaSnapshot::create([
            'period_id' => $periodId, 'region' => $region, 'section' => $section,
            'payload' => $payload, 'computed_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['blizzard.mplus_leaderboard.regions' => ['eu', 'us']]);
        GameDataPeriod::create(['period_id' => 1002, 'region' => 'eu', 'start_at' => now()->subDay(), 'end_at' => now()->addWeek()]);
    }

    public function test_periods_lists_snapshotted_periods_newest_first(): void
    {
        $this->snapshot(1001, 'all', 'specs', ['brackets' => []]);
        $this->snapshot(1002, 'all', 'specs', ['brackets' => []]);

        $response = $this->getJson('/api/v1/meta/periods');

        $response->assertOk();
        $this->assertSame([1002, 1001], array_column($response->json('periods'), 'period_id'));
        $this->assertTrue($response->json('periods.0.is_current'));
        $this->assertNotNull($response->json('periods.0.start_at'));
    }

    public function test_specs_defaults_to_latest_period_and_all_region(): void
    {
        $this->snapshot(1001, 'all', 'specs', ['brackets' => ['all' => ['roles' => [], 'total_runs' => 1]]]);
        $this->snapshot(1002, 'all', 'specs', ['brackets' => ['all' => ['roles' => [], 'total_runs' => 9]]]);

        $response = $this->getJson('/api/v1/meta/specs');

        $response->assertOk();
        $this->assertSame(1002, $response->json('period_id'));
        $this->assertSame('all', $response->json('region'));
        $this->assertSame(9, $response->json('brackets.all.total_runs'));
    }

    public function test_specs_honors_period_and_region_params(): void
    {
        $this->snapshot(1001, 'eu', 'specs', ['brackets' => ['all' => ['roles' => [], 'total_runs' => 5]]]);

        $response = $this->getJson('/api/v1/meta/specs?period=1001&region=eu');

        $response->assertOk();
        $this->assertSame(5, $response->json('brackets.all.total_runs'));
    }

    public function test_dungeons_includes_trends_from_prior_snapshots(): void
    {
        $this->snapshot(1001, 'all', 'dungeons', ['dungeons' => [['dungeon_id' => 504, 'timed_rate' => 0.5]], 'dungeon_of_the_week' => 504]);
        $this->snapshot(1002, 'all', 'dungeons', ['dungeons' => [['dungeon_id' => 504, 'timed_rate' => 0.75]], 'dungeon_of_the_week' => 504]);

        $response = $this->getJson('/api/v1/meta/dungeons');

        $response->assertOk();
        $this->assertSame(
            [['period_id' => 1001, 'timed_rate' => 0.5], ['period_id' => 1002, 'timed_rate' => 0.75]],
            $response->json('trends.504'),
        );
    }

    public function test_missing_snapshot_returns_not_warmed_404(): void
    {
        $this->getJson('/api/v1/meta/comps')
            ->assertNotFound()
            ->assertJson(['status' => 'not_warmed']);
    }

    public function test_sections_include_computed_at(): void
    {
        $this->snapshot(1002, 'all', 'specs', ['brackets' => []]);
        $this->snapshot(1002, 'all', 'dungeons', ['dungeons' => [], 'dungeon_of_the_week' => null]);

        $specs = $this->getJson('/api/v1/meta/specs');
        $specs->assertOk();
        $this->assertNotNull($specs->json('computed_at'));

        // The dungeons decorator (trends) must not drop it.
        $dungeons = $this->getJson('/api/v1/meta/dungeons');
        $dungeons->assertOk();
        $this->assertNotNull($dungeons->json('computed_at'));
    }
}
