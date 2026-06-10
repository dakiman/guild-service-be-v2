<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Models\Character;
use App\Models\CharacterAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCharacterAchievementsStressTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_insert_handles_thirty_thousand_rows(): void
    {
        $character = Character::factory()->create();

        $rows = [];
        $now = now();
        for ($i = 1; $i <= 30000; $i++) {
            $rows[] = [
                'character_id' => $character->id,
                'achievement_id' => $i,
                'completed_timestamp' => $i % 7 === 0 ? null : 1700000000000 + $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $start = microtime(true);
        foreach (array_chunk($rows, 1000) as $chunk) {
            CharacterAchievement::insert($chunk);
        }
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertSame(30000, CharacterAchievement::where('character_id', $character->id)->count());

        // Sanity ceiling. Local SQLite typically runs in ~1-3s; bumping past 10s
        // strongly suggests an O(N^2) regression in the chunking code path.
        $this->assertLessThan(
            10000,
            $elapsedMs,
            "30k-row bulk insert took {$elapsedMs} ms — investigate before shipping."
        );
    }

    public function test_delete_then_bulk_insert_replaces_existing_rows(): void
    {
        $character = Character::factory()->create();

        CharacterAchievement::insert([
            ['character_id' => $character->id, 'achievement_id' => 1, 'completed_timestamp' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['character_id' => $character->id, 'achievement_id' => 2, 'completed_timestamp' => 200, 'created_at' => now(), 'updated_at' => now()],
        ]);

        CharacterAchievement::where('character_id', $character->id)->delete();
        CharacterAchievement::insert([
            ['character_id' => $character->id, 'achievement_id' => 2, 'completed_timestamp' => 250, 'created_at' => now(), 'updated_at' => now()],
            ['character_id' => $character->id, 'achievement_id' => 3, 'completed_timestamp' => 300, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = CharacterAchievement::where('character_id', $character->id)
            ->orderBy('achievement_id')
            ->get(['achievement_id', 'completed_timestamp']);

        $this->assertCount(2, $rows);
        $this->assertSame([2, 3], $rows->pluck('achievement_id')->all());
        $this->assertSame(250, $rows[0]->completed_timestamp);
    }
}
