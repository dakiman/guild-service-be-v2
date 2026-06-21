<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopKeysControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_highest_timed_key_per_dungeon(): void
    {
        $char = Character::factory()->create([
            'name' => 'testplayer',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'class_id' => 1,
        ]);

        $highRun = DungeonRun::factory()->create([
            'dungeon_id' => 100,
            'dungeon_name' => 'Mechagon City',
            'keystone_level' => 22,
            'duration' => 1800000,
            'is_completed_on_time' => true,
        ]);

        $lowRun = DungeonRun::factory()->create([
            'dungeon_id' => 100,
            'dungeon_name' => 'Mechagon City',
            'keystone_level' => 18,
            'duration' => 1900000,
            'is_completed_on_time' => true,
        ]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        $highRun->members()->attach($char->id, $pivotData);
        $lowRun->members()->attach($char->id, $pivotData);

        $response = $this->getJson('/api/v1/stats/characters/top-keys');

        $response->assertOk()
            ->assertJsonStructure([
                'dungeons' => [
                    [
                        'dungeon_id',
                        'dungeon_name',
                        'key_level',
                        'duration',
                        'character',
                    ],
                ],
            ]);

        $dungeons = $response->json('dungeons');
        $this->assertCount(1, $dungeons);
        $this->assertEquals(100, $dungeons[0]['dungeon_id']);
        $this->assertEquals('Mechagon City', $dungeons[0]['dungeon_name']);
        $this->assertEquals(22, $dungeons[0]['key_level']);
        $this->assertEquals(1800000, $dungeons[0]['duration']);
        $this->assertEquals('testplayer', $dungeons[0]['character']['name']);
        $this->assertEquals('the-maelstrom', $dungeons[0]['character']['realm']);
        $this->assertEquals('eu', $dungeons[0]['character']['region']);
        $this->assertEquals(1, $dungeons[0]['character']['class_id']);
    }

    public function test_excludes_untimed_runs(): void
    {
        $char = Character::factory()->create(['class_id' => 1]);

        $timedRun = DungeonRun::factory()->create([
            'dungeon_id' => 200,
            'dungeon_name' => 'City of Threads',
            'keystone_level' => 15,
            'is_completed_on_time' => true,
        ]);

        $untimedRun = DungeonRun::factory()->create([
            'dungeon_id' => 200,
            'dungeon_name' => 'City of Threads',
            'keystone_level' => 25,
            'is_completed_on_time' => false,
        ]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        $timedRun->members()->attach($char->id, $pivotData);
        $untimedRun->members()->attach($char->id, $pivotData);

        $response = $this->getJson('/api/v1/stats/characters/top-keys');

        $response->assertOk();
        $dungeons = $response->json('dungeons');
        $this->assertCount(1, $dungeons);
        $this->assertEquals(15, $dungeons[0]['key_level']);
    }

    public function test_query_count_does_not_scale_with_dungeon_count(): void
    {
        // Top run per dungeon + eager-loaded members/characters must stay a small
        // fixed number of queries regardless of how many dungeons exist. (P2.2)
        $char = Character::factory()->create(['class_id' => 1]);
        $pivot = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 71,
            'spec_name' => 'Arms',
            'equipped_item_level' => 630,
        ];

        foreach (range(1, 5) as $i) {
            foreach ([20, 18] as $level) {
                $run = DungeonRun::factory()->create([
                    'dungeon_id' => 100 + $i,
                    'keystone_level' => $level,
                    'is_completed_on_time' => true,
                ]);
                $run->members()->attach($char->id, $pivot);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/stats/characters/top-keys');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount(5, $response->json('dungeons'));
        // 1 (ranked top run/dungeon) + 1 (memberEntries) + 1 (characters) — never N per dungeon.
        $this->assertLessThanOrEqual(4, $count, "Expected no N+1; ran {$count} queries.");
    }

    public function test_returns_empty_when_no_data(): void
    {
        $response = $this->getJson('/api/v1/stats/characters/top-keys');

        $response->assertOk()
            ->assertJson(['dungeons' => []]);
    }

    public function test_returns_multiple_dungeons(): void
    {
        $char = Character::factory()->create(['class_id' => 8]);

        $run1 = DungeonRun::factory()->create([
            'dungeon_id' => 100,
            'dungeon_name' => 'Mechagon City',
            'keystone_level' => 20,
            'is_completed_on_time' => true,
        ]);

        $run2 = DungeonRun::factory()->create([
            'dungeon_id' => 200,
            'dungeon_name' => 'City of Threads',
            'keystone_level' => 18,
            'is_completed_on_time' => true,
        ]);

        $pivotData = [
            'character_name' => $char->name,
            'character_realm' => $char->realm,
            'character_region' => $char->region,
            'spec_id' => 62,
            'spec_name' => 'Fire',
            'equipped_item_level' => 625,
        ];

        $run1->members()->attach($char->id, $pivotData);
        $run2->members()->attach($char->id, $pivotData);

        $response = $this->getJson('/api/v1/stats/characters/top-keys');

        $response->assertOk();
        $dungeons = $response->json('dungeons');
        $this->assertCount(2, $dungeons);
    }
}
