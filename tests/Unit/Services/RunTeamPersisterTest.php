<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Services\RunTeamPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RunTeamPersisterTest extends TestCase
{
    use RefreshDatabase;

    private RunTeamPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persister = new RunTeamPersister;
    }

    public function test_sync_team_upserts_all_members(): void
    {
        $run = DungeonRun::factory()->create();
        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => 'Tarren Mill', 'specialization_id' => 259, 'specialization' => 'Assassination', 'equipped_item_level' => 489],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => 'Kazzak', 'specialization_id' => 65, 'specialization' => 'Holy', 'equipped_item_level' => 495],
        ];

        $this->persister->syncTeam($run, $team, 'eu');

        $this->assertSame(2, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
    }

    public function test_sync_team_resolves_character_id_for_known_characters(): void
    {
        $character = Character::factory()->create([
            'name' => 'alice',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        $run = DungeonRun::factory()->create();
        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Assassination', 'equipped_item_level' => 489],
        ];

        $this->persister->syncTeam($run, $team, 'eu');

        $member = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
        $this->assertSame($character->id, $member->character_id);
    }

    public function test_sync_team_prunes_stale_members(): void
    {
        $run = DungeonRun::factory()->create();
        $oldTeam = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Holy', 'equipped_item_level' => 490],
        ];
        $this->persister->syncTeam($run, $oldTeam, 'eu');
        $this->assertSame(2, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());

        $newTeam = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
        ];
        $this->persister->syncTeam($run, $newTeam, 'eu');
        $this->assertSame(1, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
    }

    public function test_sync_team_rerun_updates_in_place_without_duplicates(): void
    {
        $run = DungeonRun::factory()->create();
        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => 259, 'specialization' => 'Assassination', 'equipped_item_level' => 480],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => 65, 'specialization' => 'Holy', 'equipped_item_level' => 490],
        ];
        $this->persister->syncTeam($run, $team, 'eu');

        // Re-sync same seats with changed gear/spec — must update, not duplicate.
        $team[0]['equipped_item_level'] = 500;
        $team[0]['specialization'] = 'Subtlety';
        $this->persister->syncTeam($run, $team, 'eu');

        $this->assertSame(2, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
        $alice = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)->where('character_name', 'Alice')->first();
        $this->assertSame(500, $alice->equipped_item_level);
        $this->assertSame('Subtlety', $alice->spec_name);
    }

    public function test_sync_team_resolves_character_ids_for_multiple_members_in_batch(): void
    {
        $alice = Character::factory()->create(['name' => 'alice', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail']);
        $bob = Character::factory()->create(['name' => 'bob', 'realm' => 'kazzak', 'region' => 'eu', 'game_version' => 'retail']);
        $run = DungeonRun::factory()->create();

        $this->persister->syncTeam($run, [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Assassination', 'equipped_item_level' => 480],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Holy', 'equipped_item_level' => 490],
            ['name' => 'Cara', 'realm' => 'draenor', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 470],
        ], 'eu');

        $members = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->get()->keyBy('character_name');
        $this->assertSame($alice->id, $members['Alice']->character_id);
        $this->assertSame($bob->id, $members['Bob']->character_id);
        $this->assertNull($members['Cara']->character_id);
    }

    public function test_upsert_member_adds_single_member_without_pruning(): void
    {
        $run = DungeonRun::factory()->create();

        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Holy', 'equipped_item_level' => 490],
        ];
        $this->persister->syncTeam($run, $team, 'eu');

        $this->persister->upsertMember($run, [
            'name' => 'Cara', 'realm' => 'draenor', 'realm_name' => 'Draenor',
            'specialization_id' => 73, 'specialization' => 'Protection', 'equipped_item_level' => 492,
        ], 'eu');

        $this->assertSame(3, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
    }

    public function test_sync_team_resolves_character_id_with_mb_safe_lowercasing(): void
    {
        // Character.name is stored canonically (BlizzardIdentity::name = mb_strtolower).
        $character = Character::factory()->create([
            'name' => 'älvïna',
            'realm' => 'kazzak',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        $run = DungeonRun::factory()->create();

        // raider.io hands a display-cased name with an uppercase non-ASCII lead;
        // strtolower() leaves 'Ä' untouched → resolves to NULL even though tracked. (P1.3)
        $this->persister->syncTeam($run, [
            ['name' => 'Älvïna', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
        ], 'eu');

        $member = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
        $this->assertSame($character->id, $member->character_id);
    }

    public function test_upsert_member_resolves_character_id_with_mb_safe_lowercasing(): void
    {
        $character = Character::factory()->create([
            'name' => 'älvïna',
            'realm' => 'kazzak',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        $run = DungeonRun::factory()->create();

        $this->persister->upsertMember($run, [
            'name' => 'Älvïna', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480,
        ], 'eu');

        $member = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
        $this->assertSame($character->id, $member->character_id);
    }

    public function test_upsert_member_updates_existing_member(): void
    {
        $run = DungeonRun::factory()->create();

        $this->persister->upsertMember($run, [
            'name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null,
            'specialization_id' => 259, 'specialization' => 'Assassination', 'equipped_item_level' => 480,
        ], 'eu');

        $this->persister->upsertMember($run, [
            'name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => 'Tarren Mill',
            'specialization_id' => 260, 'specialization' => 'Subtlety', 'equipped_item_level' => 495,
        ], 'eu');

        $this->assertSame(1, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
        $member = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
        $this->assertSame(495, $member->equipped_item_level);
        $this->assertSame('Subtlety', $member->spec_name);
    }
}
