<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Models\Character;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
}
