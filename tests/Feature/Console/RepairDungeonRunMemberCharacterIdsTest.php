<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairDungeonRunMemberCharacterIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_modify_data(): void
    {
        [$run, $a, $b, $rowIds] = $this->seedScenario();

        $exit = Artisan::call('blizzard:repair-dungeon-run-member-character-ids', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Found 2 stale rows', $output);

        // Nothing changed.
        $this->assertSame($a->id, DB::table('dungeon_run_members')->where('id', $rowIds['matching'])->value('character_id'));
        $this->assertSame($b->id, DB::table('dungeon_run_members')->where('id', $rowIds['name_mismatch'])->value('character_id'));
        $this->assertSame($b->id, DB::table('dungeon_run_members')->where('id', $rowIds['realm_mismatch'])->value('character_id'));
        $this->assertNull(DB::table('dungeon_run_members')->where('id', $rowIds['floater'])->value('character_id'));
    }

    public function test_repairs_only_mismatched_rows(): void
    {
        [$run, $a, $b, $rowIds] = $this->seedScenario();

        $exit = Artisan::call('blizzard:repair-dungeon-run-member-character-ids');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Repaired 2 rows', $output);

        $this->assertSame($a->id, DB::table('dungeon_run_members')->where('id', $rowIds['matching'])->value('character_id'));
        $this->assertNull(DB::table('dungeon_run_members')->where('id', $rowIds['name_mismatch'])->value('character_id'));
        $this->assertNull(DB::table('dungeon_run_members')->where('id', $rowIds['realm_mismatch'])->value('character_id'));
        $this->assertNull(DB::table('dungeon_run_members')->where('id', $rowIds['floater'])->value('character_id'));
    }

    public function test_returns_success_when_no_stale_rows(): void
    {
        $run = DungeonRun::factory()->create();
        $a = Character::factory()->create([
            'name' => 'matchingname',
            'realm' => 'silvermoon',
            'region' => 'eu',
        ]);

        $rowId = DB::table('dungeon_run_members')->insertGetId([
            'dungeon_run_id' => $run->id,
            'character_id' => $a->id,
            'character_name' => 'Matchingname',
            'character_realm' => 'silvermoon',
            'character_region' => 'eu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('blizzard:repair-dungeon-run-member-character-ids');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Found 0 stale rows', $output);
        $this->assertSame($a->id, DB::table('dungeon_run_members')->where('id', $rowId)->value('character_id'));
    }

    /**
     * @return array{0: DungeonRun, 1: Character, 2: Character, 3: array<string, int>}
     */
    private function seedScenario(): array
    {
        $run = DungeonRun::factory()->create();

        $a = Character::factory()->create([
            'name' => 'matchingname',
            'realm' => 'silvermoon',
            'region' => 'eu',
        ]);
        $b = Character::factory()->create([
            'name' => 'different',
            'realm' => 'kazzak',
            'region' => 'eu',
        ]);

        $rowIds = [];
        $rowIds['matching'] = DB::table('dungeon_run_members')->insertGetId([
            'dungeon_run_id' => $run->id,
            'character_id' => $a->id,
            'character_name' => 'Matchingname',
            'character_realm' => 'silvermoon',
            'character_region' => 'eu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rowIds['name_mismatch'] = DB::table('dungeon_run_members')->insertGetId([
            'dungeon_run_id' => $run->id,
            'character_id' => $b->id,
            'character_name' => 'Stranger',
            'character_realm' => 'kazzak',
            'character_region' => 'eu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rowIds['realm_mismatch'] = DB::table('dungeon_run_members')->insertGetId([
            'dungeon_run_id' => $run->id,
            'character_id' => $b->id,
            'character_name' => 'different',
            'character_realm' => 'argent-dawn',
            'character_region' => 'eu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rowIds['floater'] = DB::table('dungeon_run_members')->insertGetId([
            'dungeon_run_id' => $run->id,
            'character_id' => null,
            'character_name' => 'Floater',
            'character_realm' => 'kazzak',
            'character_region' => 'eu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$run, $a, $b, $rowIds];
    }
}
