<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildScopeNameSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_query_shorter_than_two_chars(): void
    {
        Guild::factory()->create(['name' => 'echo', 'realm' => 'tarren-mill', 'region' => 'eu']);

        $this->assertCount(0, Guild::nameSearch('e')->get());
        $this->assertCount(0, Guild::nameSearch('')->get());
    }

    public function test_prefix_matches_rank_above_substring_matches(): void
    {
        Guild::factory()->create(['name' => 'aecho', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 100]);
        Guild::factory()->create(['name' => 'echo', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 1]);

        $rows = Guild::nameSearch('ech')->get();

        $this->assertSame(['echo', 'aecho'], $rows->pluck('name')->all());
    }

    public function test_within_tier_ranks_by_num_of_searches_desc_then_name_asc(): void
    {
        Guild::factory()->create(['name' => 'echb', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 50]);
        Guild::factory()->create(['name' => 'echa', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 50]);
        Guild::factory()->create(['name' => 'echc', 'realm' => 'r', 'region' => 'kr', 'num_of_searches' => 99]);

        $rows = Guild::nameSearch('ech')->get();

        $this->assertSame(['echc', 'echa', 'echb'], $rows->pluck('name')->all());
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Guild::factory()->create([
                'name' => 'ech'.$i,
                'realm' => 'r',
                'region' => 'eu',
                'num_of_searches' => $i,
            ]);
        }

        $this->assertCount(8, Guild::nameSearch('ech')->get());
    }

    public function test_lowercases_input(): void
    {
        Guild::factory()->create(['name' => 'echo', 'realm' => 'r', 'region' => 'eu']);

        $this->assertCount(1, Guild::nameSearch('Ech')->get());
        $this->assertCount(1, Guild::nameSearch('ECHO')->get());
    }
}
