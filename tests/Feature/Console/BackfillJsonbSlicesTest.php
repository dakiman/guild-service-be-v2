<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\GameDataTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillJsonbSlicesTest extends TestCase
{
    use RefreshDatabase;

    private function seedLegacyRows(Character $character): void
    {
        // character_titles.title_id is FK-constrained to game_data_titles.
        GameDataTitle::insert([
            ['id' => 205, 'name_male' => 'the Patient', 'name_female' => 'the Patient'],
            ['id' => 53, 'name_male' => 'Bloodsail Admiral', 'name_female' => 'Bloodsail Admiral'],
        ]);
        DB::table('character_titles')->insert([
            ['character_id' => $character->id, 'title_id' => 205],
            ['character_id' => $character->id, 'title_id' => 53],
        ]);
        DB::table('character_reputations')->insert([
            ['character_id' => $character->id, 'faction_id' => 2570, 'faction_name' => 'Hallowfall Arathi', 'standing' => 'revered', 'value' => 9000, 'max' => 21000],
        ]);
    }

    public function test_backfills_jsonb_from_legacy_tables_without_bumping_updated_at(): void
    {
        $character = Character::factory()->create([
            'titles_synced_at' => now(),
            'reputations_synced_at' => now(),
        ]);
        $this->seedLegacyRows($character);
        $updatedAt = $character->fresh()->updated_at;

        $this->travel(1)->hours();
        $this->artisan('characters:backfill-jsonb-slices')->assertSuccessful();

        $character->refresh();
        $this->assertSame([53, 205], $character->title_ids);
        $this->assertCount(1, $character->reputations);
        $this->assertSame(2570, $character->reputations[0]['faction_id']);
        $this->assertSame('Hallowfall Arathi', $character->reputations[0]['faction_name']);
        $this->assertTrue($character->updated_at->equalTo($updatedAt), 'backfill must not bump updated_at');
    }

    public function test_is_resumable_and_skips_already_backfilled(): void
    {
        $character = Character::factory()->create([
            'titles_synced_at' => now(),
            'title_ids' => [1],
            'reputations' => [],
        ]);
        GameDataTitle::insert([['id' => 999, 'name_male' => 'Stale Title', 'name_female' => 'Stale Title']]);
        DB::table('character_titles')->insert(['character_id' => $character->id, 'title_id' => 999]);

        $this->artisan('characters:backfill-jsonb-slices')->assertSuccessful();

        $this->assertSame([1], $character->fresh()->title_ids, 'already-backfilled character must be skipped');
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $character = Character::factory()->create(['titles_synced_at' => now()]);
        $this->seedLegacyRows($character);

        $this->artisan('characters:backfill-jsonb-slices --dry-run')
            ->expectsOutputToContain('1 characters')
            ->assertSuccessful();

        $this->assertNull($character->fresh()->title_ids);
    }
}
