<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SyncGuildDataLinksExistingCharacterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SyncGuildRoster::class]);

        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_links_existing_character_to_guild_member_on_first_roster_sync(): void
    {
        Character::factory()->create([
            'name' => 'alpha',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $this->fakeRosterWith([
            ['name' => 'Alpha', 'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
            ['name' => 'Beta',  'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 2, 'race_id' => 1, 'rank' => 1],
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $alphaMember = GuildMember::where('name', 'alpha')->firstOrFail();
        $this->assertNotNull($alphaMember->character_id, 'expected alpha to be linked to its existing Character');

        $betaMember = GuildMember::where('name', 'beta')->firstOrFail();
        $this->assertNull($betaMember->character_id, 'beta has no Character row, must remain null');
    }

    public function test_backfills_character_id_on_subsequent_run_after_character_appears(): void
    {
        $this->fakeRosterWith([
            ['name' => 'Gamma', 'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $this->assertNull(GuildMember::where('name', 'gamma')->firstOrFail()->character_id);

        $character = Character::factory()->create([
            'name' => 'gamma',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $this->assertSame($character->id, GuildMember::where('name', 'gamma')->firstOrFail()->character_id);
    }

    private function fakeRosterWith(array $members): void
    {
        // Roster pattern first (more specific), then guild-profile pattern.
        Http::fake([
            '*/data/wow/guild/*/echo/roster*' => Http::response([
                'members' => array_map(fn ($m) => [
                    'character' => [
                        'name' => $m['name'],
                        'realm' => ['name' => $m['realm'], 'slug' => strtolower(str_replace(' ', '-', $m['realm']))],
                        'level' => $m['level'],
                        'playable_class' => ['id' => $m['class_id']],
                        'playable_race' => ['id' => $m['race_id']],
                    ],
                    'rank' => $m['rank'],
                ], $members),
            ]),
            '*/data/wow/guild/*/echo*' => Http::response([
                'name' => 'Echo',
                'faction' => ['type' => 'HORDE'],
                'achievement_points' => 0,
                'member_count' => count($members),
                'created_timestamp' => 0,
                'realm' => ['name' => 'Tarren Mill', 'slug' => 'tarren-mill'],
            ]),
        ]);
    }

    public function test_upsert_does_not_include_character_id_in_update_column_list(): void
    {
        // Contract test: the upsert UPDATE clause must NOT touch character_id,
        // so concurrent SyncCharacterData::linkGuildMembers writes can't be
        // overwritten with a stale-snapshot null. character_id is set on INSERT
        // (from pre-resolve) and patched by the post-upsert backfill UPDATE
        // — never via the upsert update list.
        $this->fakeRosterWith([
            ['name' => 'Theta', 'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
        ]);

        DB::enableQueryLog();

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $upsert = collect(DB::getQueryLog())
            ->first(fn ($q) => str_contains(strtolower($q['query']), 'on conflict')
                && str_contains(strtolower($q['query']), 'guild_members'));

        $this->assertNotNull($upsert, 'expected a guild_members upsert query in the log');

        $updateClause = stristr($upsert['query'], 'do update set');
        $this->assertNotFalse($updateClause, 'expected an "on conflict ... do update" clause');
        $this->assertStringNotContainsString('character_id', $updateClause);
    }

    public function test_post_upsert_backfill_links_existing_member_when_character_appears_between_runs(): void
    {
        // Replicates Task 4's "re-run after Character appears" path under the
        // new design: the post-upsert backfill UPDATE (not the upsert UPDATE
        // list) is what fills character_id on subsequent runs.
        $guild = Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'echo',
        ]);
        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => null,
            'name' => 'iota',
            'realm' => 'tarren-mill',
        ]);

        // Character appears AFTER the GuildMember already existed without it.
        $character = Character::factory()->create([
            'name' => 'iota',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $this->fakeRosterWith([
            ['name' => 'Iota', 'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $this->assertSame(
            $character->id,
            GuildMember::where('name', 'iota')->firstOrFail()->character_id,
        );
    }
}
