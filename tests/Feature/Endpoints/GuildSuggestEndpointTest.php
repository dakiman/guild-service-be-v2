<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildSuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_suggestions_with_expected_shape(): void
    {
        Guild::factory()->create([
            'name' => 'echo',
            'realm' => 'tarren-mill',
            'display_name' => 'Echo',
            'display_realm' => 'Tarren Mill',
            'region' => 'eu',
            'faction' => 'Horde',
            'num_of_searches' => 9,
        ]);

        $res = $this->getJson('/api/v1/guilds/suggest?q=ech');

        $res->assertOk()->assertJson([
            'suggestions' => [[
                'region' => 'eu',
                'realm' => 'tarren-mill',
                'display_realm' => 'Tarren Mill',
                'name' => 'echo',
                'display_name' => 'Echo',
                'faction' => 'Horde',
            ]],
        ]);
    }

    public function test_short_query_returns_empty_array_with_200(): void
    {
        Guild::factory()->create(['name' => 'echo', 'realm' => 'r', 'region' => 'eu']);

        $this->getJson('/api/v1/guilds/suggest?q=e')->assertOk()->assertJson(['suggestions' => []]);
        $this->getJson('/api/v1/guilds/suggest?q=')->assertOk()->assertJson(['suggestions' => []]);
    }

    public function test_missing_q_returns_422(): void
    {
        $this->getJson('/api/v1/guilds/suggest')->assertStatus(422);
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Guild::factory()->create(['name' => 'ech'.$i, 'realm' => 'r', 'region' => 'eu']);
        }

        $res = $this->getJson('/api/v1/guilds/suggest?q=ech')->assertOk();
        $this->assertCount(8, $res->json('suggestions'));
    }

    public function test_throttle_returns_429_after_limit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/guilds/suggest?q=ech');
        }
        $this->getJson('/api/v1/guilds/suggest?q=ech')->assertStatus(429);
    }
}
