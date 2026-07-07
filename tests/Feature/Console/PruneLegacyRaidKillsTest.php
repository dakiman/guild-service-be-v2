<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\RaidEncounterKill;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneLegacyRaidKillsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    private function seedKills(Character $character): void
    {
        foreach (['Midnight', 'Current Season', 'Legion', 'Wrath of the Lich King'] as $i => $expansion) {
            RaidEncounterKill::factory()->create([
                'character_id' => $character->id,
                'expansion_name' => $expansion,
                'encounter_id' => 9000 + $i,
                'difficulty' => 'heroic',
            ]);
        }
    }

    public function test_prunes_legacy_rows_of_unsearched_characters_only(): void
    {
        $searched = Character::factory()->create(['num_of_searches' => 5]);
        $unsearched = Character::factory()->create(['num_of_searches' => 0]);
        $this->seedKills($searched);
        $this->seedKills($unsearched);

        $this->artisan('raids:prune-legacy', ['--batch' => 1])
            ->assertExitCode(0);

        // Unsearched: legacy gone, retained kept.
        $this->assertSame(
            ['Current Season', 'Midnight'],
            RaidEncounterKill::where('character_id', $unsearched->id)->orderBy('expansion_name')->pluck('expansion_name')->all(),
        );
        // Searched: everything kept.
        $this->assertSame(4, RaidEncounterKill::where('character_id', $searched->id)->count());
    }

    public function test_dry_run_deletes_nothing_and_reports_count(): void
    {
        $unsearched = Character::factory()->create(['num_of_searches' => 0]);
        $this->seedKills($unsearched);

        $this->artisan('raids:prune-legacy', ['--dry-run' => true])
            ->expectsOutputToContain('2 rows')
            ->assertExitCode(0);

        $this->assertSame(4, RaidEncounterKill::where('character_id', $unsearched->id)->count());
    }

    public function test_refuses_to_run_when_current_expansion_unknown(): void
    {
        \App\Models\GameDataExpansion::query()->delete();
        \Illuminate\Support\Facades\Cache::forget('raids:current-expansion-name');

        $unsearched = Character::factory()->create(['num_of_searches' => 0]);
        $this->seedKills($unsearched);

        $this->artisan('raids:prune-legacy')->assertExitCode(1);
        $this->assertSame(4, RaidEncounterKill::where('character_id', $unsearched->id)->count());
    }
}
