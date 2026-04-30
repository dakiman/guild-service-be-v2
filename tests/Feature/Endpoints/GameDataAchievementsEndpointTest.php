<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataAchievementsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixtures(): void
    {
        GameDataAchievementCategory::create([
            'id' => 1,
            'name' => 'General',
            'parent_id' => null,
            'display_order' => 0,
        ]);
        GameDataAchievementCategory::create([
            'id' => 81,
            'name' => 'Quests',
            'parent_id' => 1,
            'display_order' => 3,
        ]);
        GameDataAchievement::create([
            'id' => 5,
            'name' => 'First Quest',
            'description' => 'Complete your first quest.',
            'category_id' => 81,
            'points' => 10,
            'is_account_wide' => false,
        ]);
        GameDataAchievement::create([
            'id' => 230,
            'name' => 'Hatchling of the Talon',
            'description' => 'Obtain 50 mounts.',
            'category_id' => null,
            'points' => 10,
            'is_account_wide' => true,
        ]);
    }

    public function test_returns_achievements_with_category_block(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/achievements');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', 5);
        $response->assertJsonPath('data.0.name', 'First Quest');
        $response->assertJsonPath('data.0.points', 10);
        $response->assertJsonPath('data.0.is_account_wide', false);
        $response->assertJsonPath('data.0.category.id', 81);
        $response->assertJsonPath('data.0.category.name', 'Quests');
        $response->assertJsonPath('data.0.category.parent_id', 1);

        $response->assertJsonPath('data.1.id', 230);
        $response->assertJsonPath('data.1.is_account_wide', true);
    }

    public function test_omits_category_block_when_no_category_row(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/achievements');

        $response->assertOk();
        $response->assertJsonPath('data.1.id', 230);
        $response->assertJsonMissingPath('data.1.category');
    }

    public function test_response_carries_cache_control_and_etag_headers(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/achievements');

        $response->assertOk();
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('ETag'));
    }

    public function test_returns_304_on_matching_if_none_match(): void
    {
        $this->seedFixtures();

        $first = $this->getJson('/api/v1/game-data/achievements');
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->getJson('/api/v1/game-data/achievements', [
            'If-None-Match' => $etag,
        ]);

        $second->assertStatus(304);
        $second->assertHeader('ETag', $etag);
    }

    public function test_etag_changes_when_underlying_data_changes(): void
    {
        $this->seedFixtures();

        $first = $this->getJson('/api/v1/game-data/achievements');
        $firstEtag = $first->headers->get('ETag');

        sleep(1);
        GameDataAchievement::find(5)->update(['name' => 'First Quest (renamed)']);

        $second = $this->getJson('/api/v1/game-data/achievements');
        $this->assertNotSame($firstEtag, $second->headers->get('ETag'));
    }
}
