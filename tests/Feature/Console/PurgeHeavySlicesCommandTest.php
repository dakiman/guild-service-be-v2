<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeHeavySlicesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_truncates_both_tables_and_nulls_both_timestamps(): void
    {
        $character = Character::factory()->create([
            'achievements_synced_at' => now(),
            'collections_synced_at' => now(),
        ]);

        DB::table('character_achievements')->insert([
            'character_id' => $character->id,
            'achievement_id' => 1001,
            'completed_timestamp' => 1700000000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('character_pets')->insert([
            'character_id' => $character->id,
            'pet_id' => 1,
            'species_id' => 10,
            'name' => 'Fluffy',
            'level' => 25,
            'breed_id' => 3,
            'quality' => 'Rare',
            'is_favorite' => false,
            'creature_display_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('blizzard:purge-heavy-slices', ['--all' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('character_achievements')->count());
        $this->assertSame(0, DB::table('character_pets')->count());
        $this->assertStringContainsString('achievements:', $output);
        $this->assertStringContainsString('pets:', $output);

        $character->refresh();
        $this->assertNull($character->achievements_synced_at);
        $this->assertNull($character->collections_synced_at);
    }

    public function test_achievements_flag_only_touches_achievements(): void
    {
        $character = Character::factory()->create([
            'achievements_synced_at' => now(),
            'collections_synced_at' => now(),
        ]);

        DB::table('character_achievements')->insert([
            'character_id' => $character->id,
            'achievement_id' => 2001,
            'completed_timestamp' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('character_pets')->insert([
            'character_id' => $character->id,
            'pet_id' => 2,
            'species_id' => 20,
            'name' => 'Buddy',
            'level' => 1,
            'breed_id' => 1,
            'quality' => 'Common',
            'is_favorite' => false,
            'creature_display_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $collectionsTimestamp = $character->collections_synced_at;

        $exit = Artisan::call('blizzard:purge-heavy-slices', ['--achievements' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('character_achievements')->count(), 'achievements should be gone');
        $this->assertSame(1, DB::table('character_pets')->count(), 'pets should be untouched');
        $this->assertStringContainsString('achievements:', $output);
        $this->assertStringNotContainsString('pets:', $output);

        $character->refresh();
        $this->assertNull($character->achievements_synced_at);
        // collections_synced_at should NOT have been nulled
        $this->assertNotNull($character->collections_synced_at);
    }

    public function test_no_flag_exits_with_failure_and_error_message(): void
    {
        $exit = Artisan::call('blizzard:purge-heavy-slices');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No slice specified', $output);
    }
}
