<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopRunsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_paginated_top_runs(): void
    {
        $char = Character::factory()->create([
            'name' => 'testplayer',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'class_id' => 1,
        ]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        // Create 25 timed runs with ascending key levels
        for ($i = 1; $i <= 25; $i++) {
            $run = DungeonRun::factory()->create([
                'keystone_level' => $i,
                'duration' => 1800000,
                'is_completed_on_time' => true,
            ]);
            $run->members()->attach($char->id, $pivotData);
        }

        $response = $this->getJson('/api/v1/stats/characters/top-runs?per_page=10');

        $response->assertOk()
            ->assertJsonStructure([
                'current_page',
                'data' => [
                    [
                        'id',
                        'dungeon_id',
                        'dungeon_name',
                        'keystone_level',
                        'duration',
                        'is_completed_on_time',
                        'affixes',
                        'completed_at',
                        'members' => [
                            [
                                'name',
                                'realm',
                                'region',
                                'spec_id',
                                'spec_name',
                                'class_id',
                                'ilvl',
                            ],
                        ],
                    ],
                ],
                'last_page',
                'per_page',
                'total',
            ]);

        $data = $response->json('data');
        $this->assertCount(10, $data);
        $this->assertEquals(25, $response->json('total'));
        $this->assertEquals(3, $response->json('last_page'));

        // Highest key first
        $this->assertEquals(25, $data[0]['keystone_level']);
        $this->assertEquals(24, $data[1]['keystone_level']);

        // Member shape
        $member = $data[0]['members'][0];
        $this->assertEquals('testplayer', $member['name']);
        $this->assertEquals('the-maelstrom', $member['realm']);
        $this->assertEquals('eu', $member['region']);
        $this->assertEquals(71, $member['spec_id']);
        $this->assertEquals('Arms', $member['spec_name']);
        $this->assertEquals(1, $member['class_id']);
        $this->assertEquals(630, $member['ilvl']);
    }

    public function test_filters_by_dungeon_id(): void
    {
        $char = Character::factory()->create(['class_id' => 1]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        $targetRun = DungeonRun::factory()->create([
            'dungeon_id' => 100,
            'dungeon_name' => 'Mechagon City',
            'keystone_level' => 20,
            'is_completed_on_time' => true,
        ]);
        $targetRun->members()->attach($char->id, $pivotData);

        $otherRun = DungeonRun::factory()->create([
            'dungeon_id' => 200,
            'dungeon_name' => 'City of Threads',
            'keystone_level' => 22,
            'is_completed_on_time' => true,
        ]);
        $otherRun->members()->attach($char->id, $pivotData);

        $response = $this->getJson('/api/v1/stats/characters/top-runs?dungeon_id=100');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(100, $data[0]['dungeon_id']);
        $this->assertEquals('Mechagon City', $data[0]['dungeon_name']);
    }

    public function test_caps_per_page_at_50(): void
    {
        $response = $this->getJson('/api/v1/stats/characters/top-runs?per_page=999');

        $response->assertOk();
        $this->assertEquals(50, $response->json('per_page'));
    }

    public function test_excludes_untimed_runs(): void
    {
        $char = Character::factory()->create(['class_id' => 1]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        $timedRun = DungeonRun::factory()->create([
            'keystone_level' => 15,
            'is_completed_on_time' => true,
        ]);
        $timedRun->members()->attach($char->id, $pivotData);

        $untimedRun = DungeonRun::factory()->create([
            'keystone_level' => 25,
            'is_completed_on_time' => false,
        ]);
        $untimedRun->members()->attach($char->id, $pivotData);

        $response = $this->getJson('/api/v1/stats/characters/top-runs');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(15, $data[0]['keystone_level']);
    }

    public function test_orders_by_keystone_level_desc_then_duration_asc(): void
    {
        $char = Character::factory()->create(['class_id' => 1]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        // Same key level, different durations
        $slowRun = DungeonRun::factory()->create([
            'keystone_level' => 20,
            'duration' => 2000000,
            'is_completed_on_time' => true,
        ]);
        $slowRun->members()->attach($char->id, $pivotData);

        $fastRun = DungeonRun::factory()->create([
            'keystone_level' => 20,
            'duration' => 1500000,
            'is_completed_on_time' => true,
        ]);
        $fastRun->members()->attach($char->id, $pivotData);

        $response = $this->getJson('/api/v1/stats/characters/top-runs');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        // Faster run first when same key level
        $this->assertEquals(1500000, $data[0]['duration']);
        $this->assertEquals(2000000, $data[1]['duration']);
    }
}
