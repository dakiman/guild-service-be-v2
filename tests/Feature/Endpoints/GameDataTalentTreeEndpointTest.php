<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataTalentTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataTalentTreeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_200_with_payload_when_row_exists(): void
    {
        GameDataTalentTree::create([
            'tree_id' => 795,
            'spec_id' => 261,
            'name' => 'Subtlety Talents',
            'tree' => [
                'class_nodes' => [],
                'spec_nodes' => [],
                'hero_trees' => [],
                'edges' => [],
            ],
            'synced_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/game-data/talent-trees/795/261');

        $response->assertOk()
            ->assertJson([
                'tree_id' => 795,
                'spec_id' => 261,
                'name' => 'Subtlety Talents',
                'tree' => [
                    'class_nodes' => [],
                    'spec_nodes' => [],
                    'hero_trees' => [],
                    'edges' => [],
                ],
            ]);

        // Symfony normalizes directive ordering alphabetically; semantically
        // equivalent to the controller's "public, max-age=3600".
        $this->assertSame('max-age=3600, public', $response->headers->get('Cache-Control'));
    }

    public function test_returns_404_when_row_missing(): void
    {
        $this->getJson('/api/v1/game-data/talent-trees/9999/9999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
