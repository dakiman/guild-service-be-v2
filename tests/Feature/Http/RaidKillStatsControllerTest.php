<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\RaidEncounterKill;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
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
        $warrior = Character::factory()->create(['class_id' => 1, 'level' => 90]);
        $mage = Character::factory()->create(['class_id' => 8, 'level' => 90]);

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
        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);

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
        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);

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

    public function test_serves_stale_then_refreshes_within_grace_window(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);

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

        // 1st request caches result A (one boss).
        $first = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');
        $first->assertOk();
        $this->assertCount(1, $first->json('raids.0.bosses'));

        // A second boss kill lands.
        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'instance_id' => 1234,
            'instance_name' => 'The Voidspire',
            'encounter_id' => 5679,
            'encounter_name' => 'Shadow Weaver',
            'difficulty' => 'heroic',
            'completed_count' => 3,
        ]);

        // Advance into the stale grace window (3300 < 3400 < 3600).
        $this->travel(3400)->seconds();

        // 2nd request serves the STALE cached A and schedules a refresh.
        $second = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');
        $second->assertOk();
        $this->assertCount(1, $second->json('raids.0.bosses'), 'stale value should still be served');

        // Flush any deferred refresh callbacks that did not run on terminate.
        app(DeferredCallbackCollection::class)->invoke();

        // 3rd request serves the refreshed B (both bosses).
        $third = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');
        $third->assertOk();
        $this->assertCount(2, $third->json('raids.0.bosses'), 'refreshed value should be served');
    }

    public function test_returns_empty_raids_when_no_data(): void
    {
        $response = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic');

        $response->assertOk()
            ->assertJson([
                'raids' => [],
                'expansions' => ['Midnight'],
                'current_expansion' => 'Midnight',
            ]);
    }

    public function test_defaults_to_current_expansion(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);

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

    public function test_expansion_list_is_current_only_and_param_is_ignored(): void
    {
        $character = Character::factory()->create(['class_id' => 1, 'level' => 90]);

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
        $this->assertSame(['Midnight'], $response->json('expansions'));

        // Legacy expansion param is ignored, not honored: still current data.
        $legacy = $this->getJson('/api/v1/stats/characters/raid-kills?difficulty=heroic&expansion=The+War+Within');
        $legacy->assertOk();
        $this->assertSame('Midnight', $legacy->json('current_expansion'));
        $this->assertSame(1234, $legacy->json('raids.0.instance_id'));
    }
}
