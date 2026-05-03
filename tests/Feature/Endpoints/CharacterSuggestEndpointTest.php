<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterSuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_suggestions_with_expected_shape(): void
    {
        Character::factory()->create([
            'name' => 'melaniya',
            'realm' => 'the-maelstrom',
            'display_name' => 'Melaniya',
            'display_realm' => 'The Maelstrom',
            'region' => 'eu',
            'class_id' => 8,
            'level' => 80,
            'faction' => 'Horde',
            'num_of_searches' => 5,
        ]);

        $res = $this->getJson('/api/v1/characters/suggest?q=mel');

        $res->assertOk()->assertJson([
            'suggestions' => [[
                'region' => 'eu',
                'realm' => 'the-maelstrom',
                'display_realm' => 'The Maelstrom',
                'name' => 'melaniya',
                'display_name' => 'Melaniya',
                'class_id' => 8,
                'level' => 80,
                'faction' => 'Horde',
            ]],
        ]);
    }

    public function test_short_query_returns_empty_array_with_200(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'eu']);

        $this->getJson('/api/v1/characters/suggest?q=m')->assertOk()->assertJson(['suggestions' => []]);
        $this->getJson('/api/v1/characters/suggest?q=')->assertOk()->assertJson(['suggestions' => []]);
    }

    public function test_missing_q_returns_422(): void
    {
        $this->getJson('/api/v1/characters/suggest')->assertStatus(422);
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Character::factory()->create(['name' => 'mel'.$i, 'realm' => 'r', 'region' => 'eu']);
        }

        $res = $this->getJson('/api/v1/characters/suggest?q=mel')->assertOk();
        $this->assertCount(8, $res->json('suggestions'));
    }

    public function test_classic_characters_are_not_returned(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'eu', 'game_version' => 'classic']);

        $this->getJson('/api/v1/characters/suggest?q=mel')->assertOk()->assertJson(['suggestions' => []]);
    }

    public function test_throttle_returns_429_after_limit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/characters/suggest?q=mel');
        }
        $this->getJson('/api/v1/characters/suggest?q=mel')->assertStatus(429);
    }
}
