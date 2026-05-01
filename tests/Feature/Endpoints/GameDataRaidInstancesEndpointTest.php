<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataExpansion;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataRaidInstance;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataRaidInstancesEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    private function seedFixtures(): void
    {
        // Latest expansion has display_order=1 — see GameDataExpansionSeeder.
        // Seed two instances on the latest expansion + one on an older one.
        GameDataRaidInstance::create([
            'id' => 1296,
            'name' => 'Liberation of Undermine',
            'expansion_id' => 12,
            'display_order' => 5,
            'media_url' => 'https://example/lou.jpg',
        ]);
        GameDataRaidInstance::create([
            'id' => 1273,
            'name' => 'Nerub-ar Palace',
            'expansion_id' => 12,
            'display_order' => 1,
            'media_url' => 'https://example/nerub.jpg',
        ]);
        GameDataRaidInstance::create([
            'id' => 1207,
            'name' => 'Aberrus, the Shadowed Crucible',
            'expansion_id' => 1, // The War Within (older — display_order=2)
            'display_order' => 5,
            'media_url' => 'https://example/aberrus.jpg',
        ]);

        // Encounters under Liberation of Undermine.
        GameDataRaidEncounter::create([
            'id' => 2902,
            'raid_instance_id' => 1296,
            'name' => 'Vexie',
            'display_order' => 0,
            'creature_display_id' => 109501,
            'portrait_url' => 'https://example/cd-109501.jpg',
        ]);
        GameDataRaidEncounter::create([
            'id' => 2917,
            'raid_instance_id' => 1296,
            'name' => 'Cauldron of Carnage',
            'display_order' => 1,
            'creature_display_id' => 109502,
            'portrait_url' => 'https://example/cd-109502.jpg',
        ]);
    }

    public function test_default_returns_only_current_expansion_with_encounters_and_expansion_block(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertJsonCount(2, 'instances'); // only the two TWW raids
        // Ordered by display_order asc → Nerub-ar Palace (1) before Liberation of Undermine (5).
        $response->assertJsonPath('instances.0.id', 1273);
        $response->assertJsonPath('instances.1.id', 1296);
        $response->assertJsonPath('instances.1.name', 'Liberation of Undermine');
        $response->assertJsonPath('instances.1.media_url', 'https://example/lou.jpg');
        $response->assertJsonPath('instances.1.expansion.id', 12);
        $response->assertJsonPath('instances.1.expansion.name', 'Midnight');
        $response->assertJsonCount(2, 'instances.1.encounters');
        $response->assertJsonPath('instances.1.encounters.0.id', 2902);
        $response->assertJsonPath('instances.1.encounters.0.name', 'Vexie');
        $response->assertJsonPath('instances.1.encounters.0.creature_display_id', 109501);
        $response->assertJsonPath('instances.1.encounters.0.portrait_url', 'https://example/cd-109501.jpg');
    }

    public function test_expansion_current_explicit_matches_default(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances?expansion=current');

        $response->assertOk();
        $response->assertJsonCount(2, 'instances');
        $response->assertJsonPath('instances.0.id', 1273);
    }

    public function test_expansion_all_returns_every_expansion(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances?expansion=all');

        $response->assertOk();
        $response->assertJsonCount(3, 'instances');
        $ids = collect($response->json('instances'))->pluck('id')->all();
        $this->assertContains(1207, $ids); // older Aberrus instance present
        $this->assertContains(1273, $ids);
        $this->assertContains(1296, $ids);
    }

    public function test_response_carries_cache_control_header(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        // Symfony normalizes Cache-Control directives alphabetically.
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_returns_empty_data_when_no_instances_seeded(): void
    {
        // No seedFixtures call — table is empty.
        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertExactJson(['instances' => []]);
    }

    public function test_endpoint_is_public_no_auth(): void
    {
        $this->seedFixtures();

        // No auth headers — should still return 200.
        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
    }

    public function test_endpoint_returns_empty_when_no_expansions_seeded_at_all(): void
    {
        // Wipe expansions even though we seeded them in setUp.
        GameDataExpansion::query()->delete();

        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertExactJson(['instances' => []]);
        // Symfony normalizes Cache-Control directives alphabetically.
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }
}
