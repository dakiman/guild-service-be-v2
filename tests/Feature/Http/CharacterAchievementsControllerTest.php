<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\CharacterAchievement;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterAchievementsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        // Achievements slice is feature-flagged off by default. These pre-existing
        // tests exercise the happy-path response shape and assume the endpoint
        // queries the DB normally — enable the flag for this suite.
        config()->set('blizzard.sync.achievements_enabled', true);

        GameDataAchievementCategory::create(['id' => 1, 'name' => 'General', 'parent_id' => null, 'display_order' => 0]);
        GameDataAchievementCategory::create(['id' => 2, 'name' => 'Quests', 'parent_id' => 1, 'display_order' => 1]);
        GameDataAchievementCategory::create(['id' => 81, 'name' => 'Feats of Strength', 'parent_id' => null, 'display_order' => 99]);

        GameDataAchievement::create(['id' => 100, 'name' => 'First Quest', 'description' => 'd', 'category_id' => 2, 'points' => 10, 'is_account_wide' => false]);
        GameDataAchievement::create(['id' => 101, 'name' => 'Second Quest', 'description' => 'd', 'category_id' => 2, 'points' => 10, 'is_account_wide' => false]);
        GameDataAchievement::create(['id' => 102, 'name' => 'Third Quest', 'description' => 'd', 'category_id' => 2, 'points' => 10, 'is_account_wide' => false]);
        GameDataAchievement::create(['id' => 200, 'name' => 'Realm First!', 'description' => 'd', 'category_id' => 81, 'points' => 0, 'is_account_wide' => false]);
        // No game-data row for 999 — exercises the "no name" / fallback path.

        $this->character = Character::factory()->create([
            'name' => 'tester',
            'realm' => 'azshara',
            'region' => 'eu',
        ]);

        CharacterAchievement::create(['character_id' => $this->character->id, 'achievement_id' => 100, 'completed_timestamp' => 3000]);
        CharacterAchievement::create(['character_id' => $this->character->id, 'achievement_id' => 101, 'completed_timestamp' => 2000]);
        CharacterAchievement::create(['character_id' => $this->character->id, 'achievement_id' => 102, 'completed_timestamp' => 1000]);
        CharacterAchievement::create(['character_id' => $this->character->id, 'achievement_id' => 200, 'completed_timestamp' => 5000]);
        CharacterAchievement::create(['character_id' => $this->character->id, 'achievement_id' => 999, 'completed_timestamp' => null]);
    }

    public function test_returns_404_for_unknown_character(): void
    {
        $this->getJson('/api/v1/characters/eu/azshara/ghost/achievements')->assertStatus(404);
    }

    public function test_array_cursor_is_rejected_with_422(): void
    {
        // ?cursor[]=x used to reach `(string) $array` → "Array to string
        // conversion" → 500. Must be rejected as a validation error. (P1.11)
        $this->getJson('/api/v1/characters/eu/azshara/tester/achievements?cursor[]=x')
            ->assertStatus(422);
    }

    public function test_filters_feats_of_strength_by_default_and_orders_recent_first(): void
    {
        $resp = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements');
        $resp->assertOk();

        $ids = array_column($resp->json('data'), 'achievement_id');
        // 200 (FoS) excluded; null-timestamp 999 last.
        $this->assertSame([100, 101, 102, 999], $ids);
        $this->assertSame(4, $resp->json('meta.total'));

        $first = $resp->json('data.0');
        $this->assertSame('First Quest', $first['name']);
        $this->assertSame('Quests', $first['category_name']);
    }

    public function test_include_feats_includes_feats_of_strength(): void
    {
        $resp = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements?include_feats=1');
        $resp->assertOk();

        $ids = array_column($resp->json('data'), 'achievement_id');
        $this->assertSame([200, 100, 101, 102, 999], $ids);
        $this->assertSame(5, $resp->json('meta.total'));
    }

    public function test_response_drops_unused_fields(): void
    {
        $resp = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements');
        $resp->assertOk();

        $row = $resp->json('data.0');
        $this->assertSame(['achievement_id', 'completed_timestamp', 'name', 'category_name'], array_keys($row));
    }

    public function test_unresolved_achievement_returns_null_name_and_category(): void
    {
        $resp = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements');
        $resp->assertOk();

        $unresolved = collect($resp->json('data'))->firstWhere('achievement_id', 999);
        $this->assertNotNull($unresolved);
        $this->assertNull($unresolved['name']);
        $this->assertNull($unresolved['category_name']);
        $this->assertNull($unresolved['completed_timestamp']);
    }

    public function test_cursor_paginates_in_correct_order(): void
    {
        $page1 = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements?per_page=2');
        $page1->assertOk();

        $this->assertSame([100, 101], array_column($page1->json('data'), 'achievement_id'));
        $this->assertNotNull($page1->json('meta.next_cursor'));

        $cursor = $page1->json('meta.next_cursor');
        $page2 = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements?per_page=2&cursor='.urlencode($cursor));
        $page2->assertOk();

        $this->assertSame([102, 999], array_column($page2->json('data'), 'achievement_id'));
        $this->assertNull($page2->json('meta.next_cursor'));
    }

    public function test_per_page_clamped_to_max(): void
    {
        $resp = $this->getJson('/api/v1/characters/eu/azshara/tester/achievements?per_page=99999');
        $resp->assertOk();
        $this->assertSame(200, $resp->json('meta.per_page'));
    }
}
