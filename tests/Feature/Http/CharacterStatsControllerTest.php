<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_valid_response_structure(): void
    {
        Character::factory()->create([
            'class_id' => 1,
            'faction' => 'Alliance',
            'race_id' => 1,
            'active_specialization_id' => 62,
            'average_item_level' => 600,
            'mythic_plus_rating' => 2500.0,
            'achievement_points' => 30000,
        ]);

        $response = $this->getJson('/api/v1/stats/characters');

        $response->assertOk()
            ->assertJsonStructure([
                'total_characters',
                'class_distribution' => [['class_id', 'count', 'avg_ilvl', 'avg_mythic_plus_rating']],
                'spec_distribution' => [['spec_id', 'class_id', 'count']],
                'faction_distribution' => ['horde', 'alliance'],
                'race_distribution' => [['race_id', 'count']],
                'top_performers' => [
                    'mythic_plus' => [['name', 'realm', 'region', 'class_id', 'spec_id', 'value']],
                    'item_level' => [['name', 'realm', 'region', 'class_id', 'spec_id', 'value']],
                    'achievement_points' => [['name', 'realm', 'region', 'class_id', 'spec_id', 'value']],
                ],
            ]);
    }

    public function test_class_distribution_aggregates_correctly(): void
    {
        Character::factory()->create(['class_id' => 1, 'average_item_level' => 600, 'mythic_plus_rating' => 2000.0]);
        Character::factory()->create(['class_id' => 1, 'average_item_level' => 500, 'mythic_plus_rating' => 1000.0]);
        Character::factory()->create(['class_id' => 2, 'average_item_level' => 650, 'mythic_plus_rating' => 3000.0]);

        $response = $this->getJson('/api/v1/stats/characters');
        $response->assertOk();

        $data = $response->json();
        $classDist = collect($data['class_distribution']);

        $class1 = $classDist->firstWhere('class_id', 1);
        $this->assertEquals(2, $class1['count']);
        $this->assertEquals(550.0, $class1['avg_ilvl']);
        $this->assertEquals(1500.0, $class1['avg_mythic_plus_rating']);

        $class2 = $classDist->firstWhere('class_id', 2);
        $this->assertEquals(1, $class2['count']);
        $this->assertEquals(650.0, $class2['avg_ilvl']);
        $this->assertEquals(3000.0, $class2['avg_mythic_plus_rating']);
    }

    public function test_faction_distribution_counts_correctly(): void
    {
        Character::factory()->count(3)->create(['faction' => 'Horde']);
        Character::factory()->count(2)->create(['faction' => 'Alliance']);

        $response = $this->getJson('/api/v1/stats/characters');
        $response->assertOk();

        $faction = $response->json('faction_distribution');
        $this->assertEquals(3, $faction['horde']);
        $this->assertEquals(2, $faction['alliance']);
    }

    public function test_top_performers_returns_max_5_sorted_descending(): void
    {
        // Create 7 characters with distinct mythic_plus_rating
        for ($i = 1; $i <= 7; $i++) {
            Character::factory()->create([
                'name' => "player{$i}",
                'mythic_plus_rating' => $i * 500.0,
                'average_item_level' => 400 + $i * 10,
                'achievement_points' => $i * 1000,
            ]);
        }

        $response = $this->getJson('/api/v1/stats/characters');
        $response->assertOk();

        $topMplus = $response->json('top_performers.mythic_plus');
        $this->assertCount(5, $topMplus);
        $this->assertEquals(3500.0, $topMplus[0]['value']);
        $this->assertEquals(3000.0, $topMplus[1]['value']);

        $topIlvl = $response->json('top_performers.item_level');
        $this->assertCount(5, $topIlvl);
        $this->assertEquals(470.0, $topIlvl[0]['value']);

        $topAchievements = $response->json('top_performers.achievement_points');
        $this->assertCount(5, $topAchievements);
        $this->assertEquals(7000.0, $topAchievements[0]['value']);
    }

    public function test_empty_state_returns_valid_empty_response(): void
    {
        $response = $this->getJson('/api/v1/stats/characters');

        $response->assertOk()
            ->assertJson([
                'total_characters' => 0,
                'class_distribution' => [],
                'spec_distribution' => [],
                'faction_distribution' => ['horde' => 0, 'alliance' => 0],
                'race_distribution' => [],
                'top_performers' => [
                    'mythic_plus' => [],
                    'item_level' => [],
                    'achievement_points' => [],
                ],
            ]);
    }

    public function test_spec_distribution_groups_by_spec_and_class(): void
    {
        Character::factory()->count(3)->create(['active_specialization_id' => 62, 'class_id' => 8]);
        Character::factory()->count(2)->create(['active_specialization_id' => 63, 'class_id' => 8]);
        Character::factory()->create(['active_specialization_id' => 70, 'class_id' => 2]);

        $response = $this->getJson('/api/v1/stats/characters');
        $response->assertOk();

        $specDist = collect($response->json('spec_distribution'));
        $this->assertCount(3, $specDist);

        $spec62 = $specDist->firstWhere('spec_id', 62);
        $this->assertEquals(3, $spec62['count']);
        $this->assertEquals(8, $spec62['class_id']);
    }

    public function test_race_distribution_counts_correctly(): void
    {
        Character::factory()->count(4)->create(['race_id' => 2]);
        Character::factory()->count(2)->create(['race_id' => 10]);
        Character::factory()->create(['race_id' => 7]);

        $response = $this->getJson('/api/v1/stats/characters');
        $response->assertOk();

        $raceDist = collect($response->json('race_distribution'));
        $this->assertEquals(4, $raceDist->firstWhere('race_id', 2)['count']);
        $this->assertEquals(2, $raceDist->firstWhere('race_id', 10)['count']);
        $this->assertEquals(1, $raceDist->firstWhere('race_id', 7)['count']);
    }
}
