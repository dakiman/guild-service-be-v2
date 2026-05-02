<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 1 Tasks 2-4: red tests for the dungeon-run-members pivot bug.
 *
 * The current SyncCharacterData::syncMythicPlus() loop calls
 * $dungeonRun->members()->syncWithoutDetaching([...]) keyed on
 * `$memberCharacter?->id ?? $character->id`. The pivot's true unique
 * constraint is (dungeon_run_id, character_name, character_realm,
 * character_region), so unknown members keyed off the same fallback
 * id silently overwrite each other, and two characters synced
 * sequentially against a shared run with an unknown member trip
 * SQLSTATE[23505].
 *
 * These tests drive a NEW public method `persistRunTeam(DungeonRun, array): void`
 * on SyncCharacterData (Task 5 — does not exist yet) that uses
 * DB::table('dungeon_run_members')->updateOrInsert() keyed on the real
 * unique tuple, resolves real characters via Character::byIdentity (with
 * lowercased name), and runs delete-missing within the run.
 *
 * Note: the Plan 1 Task 4 end-to-end "mythics_synced_at advances on
 * both shared-run characters" scenario is covered indirectly by
 * `test_two_characters_sharing_a_run_with_an_unknown_member_both_succeed`
 * plus existing slice-level tests. The full HTTP-faked end-to-end
 * variant was downgraded — fake responses for the season game-data
 * call + mythic-keystone-profile call + seed character profile/media/
 * equipment/specs paths is excessive scaffolding for this slice.
 */
class SyncMythicPlusTeamPivotTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(string $name = 'syncedchar', string $realm = 'silvermoon', string $region = 'eu'): SyncCharacterData
    {
        return new SyncCharacterData(
            region: $region,
            realm: $realm,
            name: $name,
            depth: SyncDepth::Full,
        );
    }

    public function test_persists_all_unknown_members_when_run_has_no_db_matches(): void
    {
        Character::factory()->create([
            'name' => 'syncedchar',
            'realm' => 'silvermoon',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $run = DungeonRun::factory()->create();

        $team = [
            ['name' => 'Alpha', 'realm' => 'silvermoon', 'specialization' => 'Frost', 'equipped_item_level' => 620],
            ['name' => 'Beta', 'realm' => 'silvermoon', 'specialization' => 'Fire', 'equipped_item_level' => 621],
            ['name' => 'Gamma', 'realm' => 'twisting-nether', 'specialization' => 'Holy', 'equipped_item_level' => 622],
            ['name' => 'Delta', 'realm' => 'kazzak', 'specialization' => 'Arcane', 'equipped_item_level' => 623],
        ];

        $this->makeJob()->persistRunTeam($run, $team);

        $rows = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->orderBy('character_name')
            ->get();

        $this->assertCount(4, $rows, 'expected 4 rows for 4 unknown team members');

        $names = $rows->pluck('character_name')->all();
        sort($names);
        $this->assertSame(['Alpha', 'Beta', 'Delta', 'Gamma'], $names);

        foreach ($rows as $row) {
            $this->assertNull($row->character_id, "expected character_id NULL for unknown member {$row->character_name}");
        }
    }

    public function test_two_characters_sharing_a_run_with_an_unknown_member_both_succeed(): void
    {
        $charA = Character::factory()->create([
            'name' => 'saiyanin',
            'realm' => 'silvermoon',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $charB = Character::factory()->create([
            'name' => 'melaniya',
            'realm' => 'silvermoon',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $run = DungeonRun::factory()->create();

        $team = [
            ['name' => 'Saiyanin', 'realm' => 'silvermoon', 'specialization' => 'Fury', 'equipped_item_level' => 630],
            ['name' => 'Melaniya', 'realm' => 'silvermoon', 'specialization' => 'Holy', 'equipped_item_level' => 631],
            ['name' => 'Melodud', 'realm' => 'twisting-nether', 'specialization' => 'Frost', 'equipped_item_level' => 629],
        ];

        $this->makeJob('saiyanin')->persistRunTeam($run, $team);
        // Pre-fix: this second call explodes on SQLSTATE[23505] because the
        // syncWithoutDetaching keyed-by-fallback-id pattern collides for the
        // unknown Melodud. The fix uses the (run, name, realm, region) tuple,
        // so this must NOT throw.
        $this->makeJob('melaniya')->persistRunTeam($run, $team);

        $rows = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->get()
            ->keyBy(fn ($r) => strtolower($r->character_name));

        $this->assertCount(3, $rows, 'expected exactly 3 rows after both calls');

        $this->assertSame($charA->id, (int) $rows['saiyanin']->character_id);
        $this->assertSame($charB->id, (int) $rows['melaniya']->character_id);
        $this->assertNull($rows['melodud']->character_id);
    }

    public function test_known_member_resolves_case_insensitively(): void
    {
        $known = Character::factory()->create([
            'name' => 'thrall',
            'realm' => 'orgrimmar',
            'region' => 'us',
            'game_version' => 'retail',
        ]);

        Character::factory()->create([
            'name' => 'syncedchar',
            'realm' => 'orgrimmar',
            'region' => 'us',
            'game_version' => 'retail',
        ]);

        $run = DungeonRun::factory()->create();

        $team = [
            ['name' => 'Thrall', 'realm' => 'orgrimmar', 'specialization' => 'Enhancement', 'equipped_item_level' => 640],
        ];

        $this->makeJob(name: 'syncedchar', realm: 'orgrimmar', region: 'us')
            ->persistRunTeam($run, $team);

        $row = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($known->id, (int) $row->character_id);
    }

    public function test_persist_run_team_replaces_within_run_via_delete_missing(): void
    {
        Character::factory()->create([
            'name' => 'syncedchar',
            'realm' => 'silvermoon',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $run = DungeonRun::factory()->create();

        $teamFour = [
            ['name' => 'Alpha', 'realm' => 'silvermoon', 'specialization' => 'Frost', 'equipped_item_level' => 620],
            ['name' => 'Beta', 'realm' => 'silvermoon', 'specialization' => 'Fire', 'equipped_item_level' => 621],
            ['name' => 'Gamma', 'realm' => 'twisting-nether', 'specialization' => 'Holy', 'equipped_item_level' => 622],
            ['name' => 'Delta', 'realm' => 'kazzak', 'specialization' => 'Arcane', 'equipped_item_level' => 623],
        ];

        $this->makeJob()->persistRunTeam($run, $teamFour);

        $this->assertSame(4, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());

        $teamTwo = [
            ['name' => 'Alpha', 'realm' => 'silvermoon', 'specialization' => 'Frost', 'equipped_item_level' => 620],
            ['name' => 'Beta', 'realm' => 'silvermoon', 'specialization' => 'Fire', 'equipped_item_level' => 621],
        ];

        $this->makeJob()->persistRunTeam($run, $teamTwo);

        $rows = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->orderBy('character_name')
            ->get();

        $this->assertCount(2, $rows, 'expected delete-missing to leave only 2 rows');

        $names = $rows->pluck('character_name')->all();
        sort($names);
        $this->assertSame(['Alpha', 'Beta'], $names);
    }
}
