<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\DungeonRunMember;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CanonicalizeCharacterNamesTest extends TestCase
{
    use RefreshDatabase;

    private function makePair(): array
    {
        $keeper = Character::factory()->create([
            'name' => 'бробабади', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail', 'num_of_searches' => 2,
            'mythic_plus_rating_by_spec' => null, 'display_name' => null,
        ]);
        $loser = Character::factory()->create([
            'name' => 'Бробабади', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail', 'num_of_searches' => 1,
            'mythic_plus_rating_by_spec' => [252 => 549], 'display_name' => null,
        ]);

        return [$keeper, $loser];
    }

    public function test_merges_case_duplicate_into_canonical_keeper(): void
    {
        [$keeper, $loser] = $this->makePair();

        $run = DungeonRun::factory()->create();
        DungeonRunMember::create([
            'dungeon_run_id' => $run->id, 'character_id' => $loser->id,
            'character_name' => 'Бробабади', 'character_realm' => 'howling-fjord',
            'character_region' => 'eu',
        ]);
        // Conflicting + non-conflicting slice rows.
        DB::table('character_mounts')->insert([
            ['character_id' => $keeper->id, 'mount_id' => 1, 'name' => 'Keeper Mount'],
            ['character_id' => $loser->id, 'mount_id' => 1, 'name' => 'Dupe Mount'],  // conflict → dropped
            ['character_id' => $loser->id, 'mount_id' => 2, 'name' => 'Moved Mount'], // moves to keeper
        ]);

        $this->artisan('characters:canonicalize-names')->assertSuccessful();

        $this->assertNull(Character::find($loser->id));
        $keeper->refresh();
        $this->assertSame(3, $keeper->num_of_searches);
        $this->assertSame('Бробабади', $keeper->display_name);
        $this->assertNotNull($keeper->mythic_plus_rating_by_spec);
        $this->assertSame($keeper->id, DungeonRunMember::query()->firstOrFail()->character_id);
        $this->assertEqualsCanonicalizing(
            [1, 2],
            DB::table('character_mounts')->where('character_id', $keeper->id)->pluck('mount_id')->all(),
        );
        $this->assertSame(0, DB::table('character_mounts')->where('character_id', $loser->id)->count());
    }

    public function test_renames_lone_non_canonical_row_and_preserves_display_casing(): void
    {
        $char = Character::factory()->create([
            'name' => 'Девоуреркала', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail', 'display_name' => null,
        ]);

        $this->artisan('characters:canonicalize-names')->assertSuccessful();

        $char->refresh();
        $this->assertSame('девоуреркала', $char->name);
        $this->assertSame('Девоуреркала', $char->display_name);
    }

    public function test_guild_members_renamed_deduped_and_relinked(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu']);
        $char = Character::factory()->create([
            'name' => 'бробабади', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $lone = GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'Бробабади',
            'realm' => 'howling-fjord', 'character_id' => null,
        ]);
        // Sibling pair in a second guild: canonical row already exists → cap row deleted.
        $guild2 = Guild::factory()->create(['region' => 'eu']);
        GuildMember::factory()->create([
            'guild_id' => $guild2->id, 'name' => 'девоуреркала', 'realm' => 'howling-fjord',
        ]);
        $dupe = GuildMember::factory()->create([
            'guild_id' => $guild2->id, 'name' => 'Девоуреркала', 'realm' => 'howling-fjord',
        ]);

        $this->artisan('characters:canonicalize-names')->assertSuccessful();

        $lone->refresh();
        $this->assertSame('бробабади', $lone->name);
        $this->assertSame('Бробабади', $lone->display_name);
        $this->assertSame($char->id, $lone->character_id);   // relinked
        $this->assertNull(GuildMember::find($dupe->id));      // deduped
    }

    public function test_dry_run_changes_nothing(): void
    {
        [, $loser] = $this->makePair();

        $this->artisan('characters:canonicalize-names --dry-run')->assertSuccessful();

        $this->assertNotNull(Character::find($loser->id));
        $this->assertSame('Бробабади', $loser->fresh()->name);
    }
}
