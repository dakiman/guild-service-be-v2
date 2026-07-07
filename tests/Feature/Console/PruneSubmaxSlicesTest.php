<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\RaidEncounterKill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One-off cleanup after endgame-only gating: sub-max characters synced under
 * the old rules still carry slice rows + timestamps that will never refresh.
 */
class PruneSubmaxSlicesTest extends TestCase
{
    use RefreshDatabase;

    private function makeSyncedCharacter(string $name, int $level): Character
    {
        $now = now();
        $character = Character::factory()->create([
            'name' => $name,
            'realm' => 'azshara',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => $level,
            'stats' => ['health' => 12345],
            'title_ids' => [53],
            'reputations' => [
                ['faction_id' => 2570, 'faction_name' => 'Hallowfall Arathi', 'standing' => 'revered', 'value' => 9000, 'max' => 21000],
            ],
            'mythics_synced_at' => $now,
            'pvp_synced_at' => $now,
            'professions_synced_at' => $now,
            'raids_synced_at' => $now,
            'stats_synced_at' => $now,
            'titles_synced_at' => $now,
            'reputations_synced_at' => $now,
            'collections_synced_at' => $now,
            'achievements_synced_at' => $now,
        ]);

        RaidEncounterKill::factory()->create([
            'character_id' => $character->id,
            'expansion_name' => 'Midnight',
            'difficulty' => 'heroic',
        ]);
        DB::table('character_pvp_brackets')->insert([
            'character_id' => $character->id,
            'bracket' => '2v2',
            'rating' => 1500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $character;
    }

    public function test_prunes_slice_data_of_submax_characters_only(): void
    {
        config()->set('blizzard.endgame_level', 90);

        $lowbie = $this->makeSyncedCharacter('lowbie', 89);
        $main = $this->makeSyncedCharacter('mainchar', 90);
        $originalUpdatedAt = $lowbie->updated_at;

        $this->artisan('characters:prune-submax-slices')->assertExitCode(0);

        // Sub-max: slice rows gone, timestamps + stats nulled.
        $this->assertSame(0, RaidEncounterKill::where('character_id', $lowbie->id)->count());
        $this->assertSame(0, DB::table('character_pvp_brackets')->where('character_id', $lowbie->id)->count());
        $fresh = $lowbie->fresh();
        $this->assertNull($fresh->mythics_synced_at);
        $this->assertNull($fresh->achievements_synced_at);
        $this->assertNull($fresh->stats);
        $this->assertNull($fresh->title_ids);
        $this->assertNull($fresh->reputations);
        // The profile-sync clock must not be bumped by the prune.
        $this->assertTrue($fresh->updated_at->equalTo($originalUpdatedAt));

        // Endgame: untouched.
        $this->assertSame(1, RaidEncounterKill::where('character_id', $main->id)->count());
        $this->assertSame(1, DB::table('character_pvp_brackets')->where('character_id', $main->id)->count());
        $this->assertNotNull($main->fresh()->mythics_synced_at);
        $this->assertNotNull($main->fresh()->title_ids);
        $this->assertNotNull($main->fresh()->reputations);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        config()->set('blizzard.endgame_level', 90);

        $lowbie = $this->makeSyncedCharacter('lowbie', 89);

        $this->artisan('characters:prune-submax-slices', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(1, RaidEncounterKill::where('character_id', $lowbie->id)->count());
        $this->assertNotNull($lowbie->fresh()->mythics_synced_at);
    }
}
