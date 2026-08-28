<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Teammate discovery for the crawl must be driven from the character's own
 * pivot rows (character_id index → run pkeys → members by run id), never from
 * `dungeon_runs.season`. Right after a season rollover the new season is
 * absent from pg_stats, the planner estimated ~1 row for `season = ?`, and
 * the old single-statement join ran the season scan twice — a 48k × 48k
 * nested loop that wedged every blizzard-user-sync worker for hours
 * (2026-08-23..28). The result contract stays the same: raw identity rows
 * for everyone (seed included) who appeared in the character's runs that
 * season; the job dedupes and drops the seed.
 */
class CharacterSeasonTeammatesTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(int $season, array $members): DungeonRun
    {
        $run = DungeonRun::factory()->create(['season' => $season]);

        foreach ($members as [$region, $realm, $name, $characterId]) {
            DB::table('dungeon_run_members')->insert([
                'dungeon_run_id' => $run->id,
                'character_id' => $characterId,
                'character_name' => $name,
                'character_realm' => $realm,
                'character_region' => $region,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $run;
    }

    public function test_returns_members_of_the_characters_runs_in_that_season_only(): void
    {
        $seed = Character::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'seed']);
        $other = Character::factory()->create(['region' => 'eu', 'realm' => 'kazzak', 'name' => 'other']);

        // Seed's run this season: two teammates (one untracked → null character_id).
        $this->makeRun(18, [
            ['eu', 'tarren-mill', 'seed', $seed->id],
            ['eu', 'tarren-mill', 'Alice', null],
            ['eu', 'kazzak', 'Bob', $other->id],
        ]);
        // Seed's run last season: must not leak.
        $this->makeRun(17, [
            ['eu', 'tarren-mill', 'seed', $seed->id],
            ['eu', 'draenor', 'Stale', null],
        ]);
        // Someone else's run this season: seed wasn't in it.
        $this->makeRun(18, [
            ['eu', 'kazzak', 'other', $other->id],
            ['eu', 'kazzak', 'Unrelated', null],
        ]);

        $rows = $seed->seasonTeammateRows(18)
            ->map(fn ($r) => "{$r->character_region}:{$r->character_realm}:{$r->character_name}")
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'eu:kazzak:Bob',
            'eu:tarren-mill:Alice',
            'eu:tarren-mill:seed',
        ], $rows);
    }

    public function test_returns_empty_when_the_character_has_no_runs_this_season(): void
    {
        $seed = Character::factory()->create();
        $this->makeRun(17, [[$seed->region, $seed->realm, $seed->name, $seed->id]]);

        $this->assertTrue($seed->seasonTeammateRows(18)->isEmpty());
        $this->assertTrue(Character::factory()->create()->seasonTeammateRows(18)->isEmpty());
    }
}
