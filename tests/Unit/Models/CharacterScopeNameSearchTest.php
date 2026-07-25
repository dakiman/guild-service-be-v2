<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterScopeNameSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_query_shorter_than_two_chars(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'the-maelstrom', 'region' => 'eu']);

        $this->assertCount(0, Character::nameSearch('m')->get());
        $this->assertCount(0, Character::nameSearch('')->get());
    }

    public function test_prefix_matches_rank_above_substring_matches(): void
    {
        Character::factory()->create(['name' => 'amelaniya', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 100]);
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 1]);

        $rows = Character::nameSearch('mel')->get();

        $this->assertSame(['melaniya', 'amelaniya'], $rows->pluck('name')->all());
    }

    public function test_within_tier_ranks_by_num_of_searches_desc_then_name_asc(): void
    {
        Character::factory()->create(['name' => 'melb', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 50]);
        Character::factory()->create(['name' => 'mela', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 50]);
        Character::factory()->create(['name' => 'melc', 'realm' => 'r', 'region' => 'kr', 'num_of_searches' => 99]);

        $rows = Character::nameSearch('mel')->get();

        // melc (99) → mela (50, alpha) → melb (50)
        $this->assertSame(['melc', 'mela', 'melb'], $rows->pluck('name')->all());
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Character::factory()->create([
                'name' => 'mel'.$i,
                'realm' => 'r',
                'region' => 'eu',
                'num_of_searches' => $i,
            ]);
        }

        $this->assertCount(8, Character::nameSearch('mel')->get());
    }

    public function test_lowercases_input_so_mixed_case_query_matches_canonical_storage(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'eu']);

        $this->assertCount(1, Character::nameSearch('Mel')->get());
        $this->assertCount(1, Character::nameSearch('MELANIYA')->get());
    }

    public function test_cyrillic_search_is_case_insensitive(): void
    {
        Character::factory()->create([
            'name' => 'бробабади',
            'realm' => 'howling-fjord',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $this->assertCount(1, Character::nameSearch('Бробабади')->get());
    }

    public function test_single_cyrillic_char_needle_is_rejected(): void
    {
        Character::factory()->create([
            'name' => 'бробабади',
            'realm' => 'howling-fjord',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $this->assertCount(0, Character::nameSearch('б')->get());
    }

    public function test_substring_match_works_when_query_is_in_middle_of_name(): void
    {
        Character::factory()->create(['name' => 'xxmelyy', 'realm' => 'r', 'region' => 'eu']);

        $this->assertCount(1, Character::nameSearch('mel')->get());
    }

    public function test_name_search_does_not_load_heavy_jsonb_columns(): void
    {
        // suggest only renders 8 scalars — don't haul media/talents/equipment/stats. (P2.3)
        Character::factory()->create([
            'name' => 'melaniya', 'realm' => 'r', 'region' => 'eu',
            'talents' => ['class' => [1, 2, 3]],
            'equipment' => ['head' => ['id' => 1]],
            'stats' => ['crit' => 30],
            'mythic_plus_rating_by_spec' => ['250' => 1000],
        ]);

        $row = Character::nameSearch('mel')->first();
        $attrs = $row->getAttributes();

        $this->assertArrayNotHasKey('talents', $attrs);
        $this->assertArrayNotHasKey('equipment', $attrs);
        $this->assertArrayNotHasKey('stats', $attrs);
        $this->assertArrayNotHasKey('mythic_plus_rating_by_spec', $attrs);

        // Fields the suggestion resource needs survive.
        $this->assertSame('melaniya', $row->name);
        $this->assertArrayHasKey('class_id', $attrs);
        $this->assertArrayHasKey('display_realm', $attrs);
        $this->assertArrayHasKey('faction', $attrs);
    }

    public function test_most_popular_loads_media_but_not_other_jsonb(): void
    {
        // CharacterSummaryResource renders an avatar from media → keep media,
        // drop the heavy talents/equipment/stats blobs. (P2.3)
        Character::factory()->create([
            'name' => 'melaniya', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 99,
            'media' => ['avatar' => 'https://example.test/a.jpg'],
            'talents' => ['class' => [1, 2, 3]],
            'equipment' => ['head' => ['id' => 1]],
            'stats' => ['crit' => 30],
        ]);

        $row = Character::mostPopular(5)->first();
        $attrs = $row->getAttributes();

        $this->assertSame('https://example.test/a.jpg', $row->media['avatar']);
        $this->assertArrayNotHasKey('talents', $attrs);
        $this->assertArrayNotHasKey('equipment', $attrs);
        $this->assertArrayNotHasKey('stats', $attrs);
    }
}
