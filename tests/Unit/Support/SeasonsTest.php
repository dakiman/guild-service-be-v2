<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeasonsTest extends TestCase
{
    use RefreshDatabase;

    private function seedSeason(): void
    {
        GameDataSeason::create([
            'id' => 17,
            'slug' => 'season-mn-1',
            'name' => 'Midnight Season 1',
            'raiderio_tier_slug' => 'tier-mn-1',
            'raiderio_expansion_id' => 11,
            'is_current' => true,
        ]);
    }

    public function test_current_returns_registry_row_as_array(): void
    {
        $this->seedSeason();

        $current = Seasons::current();

        $this->assertSame(17, $current['id']);
        $this->assertSame('season-mn-1', $current['slug']);
        $this->assertSame('Midnight Season 1', $current['name']);
        $this->assertSame(17, Seasons::currentId());
    }

    public function test_current_is_null_when_registry_empty(): void
    {
        $this->assertNull(Seasons::current());
        $this->assertNull(Seasons::currentId());
    }

    public function test_empty_registry_result_is_cached_not_requeried(): void
    {
        // Prime the (empty) cache, then seed. Without clearCache the cached
        // "no season" sentinel must still win — proves nulls are cached.
        $this->assertNull(Seasons::current());
        $this->seedSeason();
        $this->assertNull(Seasons::current());

        Seasons::clearCache();
        $this->assertSame(17, Seasons::currentId());
    }

    public function test_raiderio_slugs_fall_back_to_config(): void
    {
        config(['raiderio.season' => 'season-cfg', 'raiderio.current_raid_tier' => 'tier-cfg']);

        $this->assertSame('season-cfg', Seasons::raiderioSeasonSlug());
        $this->assertSame('tier-cfg', Seasons::raiderioTierSlug());

        Seasons::clearCache();
        $this->seedSeason();

        $this->assertSame('season-mn-1', Seasons::raiderioSeasonSlug());
        $this->assertSame('tier-mn-1', Seasons::raiderioTierSlug());
    }

    public function test_missing_registry_table_fails_open_to_null(): void
    {
        // Unit suites without RefreshDatabase (and the deploy window before
        // migrate runs) have no game_data_seasons table at all — the reader
        // must fail open exactly like an empty registry, not throw.
        Schema::drop('game_data_seasons');

        $this->assertNull(Seasons::current());
        $this->assertNull(Seasons::currentId());
    }
}
