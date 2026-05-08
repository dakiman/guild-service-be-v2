<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\RaidEncounterKill;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidKillStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    public function test_returns_raid_kills_grouped_by_class(): void
    {
        $warrior = Character::factory()->create(['class_id' => 1, 'level' => 80]);
        $mage = Character::factory()->create(['class_id' => 8, 'level' => 80]);

        RaidEncounterKill::factory()->create([
            'character_id' => $warrior->id,
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $mage->id,
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 5,
        ]);

        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');

        $response->assertOk()
            ->assertJsonStructure([
                'raids' => [
                    [
                        'instance_id',
                        'name',
                        'bosses' => [
                            [
                                'encounter_id',
                                'name',
                                'kills_by_class',
                            ],
                        ],
                    ],
                ],
                'expansions',
                'current_expansion',
            ]);

        $raids = $response->json('raids');
        $this->assertCount(1, $raids);
        $this->assertEquals(1234, $raids[0]['instance_id']);
        $this->assertEquals('The Voidspire', $raids[0]['name']);

        $bosses = $raids[0]['bosses'];
        $this->assertCount(1, $bosses);
        $this->assertEquals(5678, $bosses[0]['encounter_id']);
        $this->assertEquals('Voidlord Xareth', $bosses[0]['name']);

        $killsByClass = $bosses[0]['kills_by_class'];
        $this->assertEquals(10, $killsByClass['1']);
        $this->assertEquals(5, $killsByClass['8']);
    }

    public function test_filters_by_difficulty(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 80]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5679,
            'encounter_name' => 'Shadow Weaver',
            'difficulty' => 'mythic',
            'completed_count' => 3,
        ]);

        // Request heroic — should only get the heroic kill
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');
        $response->assertOk();

        $raids = $response->json('raids');
        $this->assertCount(1, $raids);
        $bosses = $raids[0]['bosses'];
        $this->assertCount(1, $bosses);
        $this->assertEquals(5678, $bosses[0]['encounter_id']);

        // Request mythic — should only get the mythic kill
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=mythic');
        $response->assertOk();

        $raids = $response->json('raids');
        $this->assertCount(1, $raids);
        $bosses = $raids[0]['bosses'];
        $this->assertCount(1, $bosses);
        $this->assertEquals(5679, $bosses[0]['encounter_id']);
    }

    public function test_defaults_to_heroic_difficulty(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 80]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5679,
            'encounter_name' => 'Shadow Weaver',
            'difficulty' => 'normal',
            'completed_count' => 5,
        ]);

        // No difficulty param — should default to heroic
        $response = $this->getJson('/api/v1/stats/characters/raid-kills');
        $response->assertOk();

        $bosses = $response->json('raids.0.bosses');
        $this->assertCount(1, $bosses);
        $this->assertEquals(5678, $bosses[0]['encounter_id']);
    }

    public function test_rejects_invalid_difficulty(): void
    {
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=impossible');
        $response->assertUnprocessable();
    }

    public function test_returns_empty_raids_when_no_data(): void
    {
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');

        $response->assertOk()
            ->assertJson([
                'raids' => [],
                'expansions' => [],
                'current_expansion' => 'Midnight',
            ]);
    }

    public function test_defaults_to_current_expansion(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 80]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'The War Within',
            'instance_id' => 2345,
            'instance_name' => 'Nerub-ar Palace',
            'encounter_id' => 6789,
            'encounter_name' => 'Queen Ansurek',
            'difficulty' => 'heroic',
            'completed_count' => 5,
        ]);

        // No expansion param — should default to current (Midnight)
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');
        $response->assertOk();

        $raids = $response->json('raids');
        $this->assertCount(1, $raids);
        $this->assertEquals(1234, $raids[0]['instance_id']);
        $this->assertEquals('The Voidspire', $raids[0]['name']);
    }

    public function test_filters_by_specific_expansion(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 80]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'The War Within',
            'instance_id' => 2345,
            'instance_name' => 'Nerub-ar Palace',
            'encounter_id' => 6789,
            'encounter_name' => 'Queen Ansurek',
            'difficulty' => 'heroic',
            'completed_count' => 5,
        ]);

        // Request TWW — should only get TWW kills
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic&expansion=The+War+Within');
        $response->assertOk();

        $raids = $response->json('raids');
        $this->assertCount(1, $raids);
        $this->assertEquals(2345, $raids[0]['instance_id']);
        $this->assertEquals('Nerub-ar Palace', $raids[0]['name']);
    }

    public function test_response_includes_expansion_list(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 80]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5678,
            'encounter_name' => 'Voidlord Xareth',
            'difficulty' => 'heroic',
            'completed_count' => 10,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'The War Within',
            'instance_id' => 2345,
            'instance_name' => 'Nerub-ar Palace',
            'encounter_id' => 6789,
            'encounter_name' => 'Queen Ansurek',
            'difficulty' => 'heroic',
            'completed_count' => 5,
        ]);

        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');
        $response->assertOk();

        $expansions = $response->json('expansions');
        $this->assertCount(2, $expansions);
        $this->assertContains('Midnight', $expansions);
        $this->assertContains('The War Within', $expansions);
    }
}
