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

    public function test_all_by_id_and_by_slug_read_the_registry_as_plain_arrays(): void
    {
        $this->seedSeason();
        GameDataSeason::create([
            'id' => 18, 'slug' => 'season-mn-2', 'name' => 'Midnight Season 2', 'raiderio_tier_slug' => 'tier-mn-2',
            'raiderio_expansion_id' => 11, 'is_current' => false, 'started_at' => '2026-08-22 00:00:00',
        ]);

        $all = Seasons::all();
        $this->assertSame([18, 17], array_keys($all));
        $this->assertFalse($all[18]['is_current']);
        $this->assertTrue($all[17]['is_current']);
        $this->assertSame('2026-08-22T00:00:00+00:00', $all[18]['started_at']);

        $this->assertSame('season-mn-1', Seasons::byId(17)['slug']);
        $this->assertSame(18, Seasons::bySlug('season-mn-2')['id']);
        $this->assertNull(Seasons::byId(99));
        $this->assertNull(Seasons::byId(null));
        $this->assertNull(Seasons::bySlug('season-nope'));
    }

    public function test_all_is_cached_until_clear_cache(): void
    {
        $this->assertSame([], Seasons::all());
        $this->seedSeason();
        $this->assertSame([], Seasons::all());

        Seasons::clearCache();
        $this->assertSame([17], array_keys(Seasons::all()));
    }
}
