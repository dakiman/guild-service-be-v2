<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P1.4: guild_id is set when the profile reports a guild but was never cleared
 * when the character leaves — ex-members counted toward guild stats forever.
 */
class SyncCharacterDataGuildLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TokenManagerInterface::class, fn () => new class implements TokenManagerInterface
        {
            public function getToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }

            public function refreshToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }
        });

        Bus::fake([SyncCharacterData::class, SyncGuildData::class]);
    }

    public function test_clears_guild_id_when_character_leaves_guild(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'echo']);

        // Single fake toggled by reference — re-calling Http::fake() merges
        // stubs (same pattern → first wins), so a toggle is needed to flip the
        // profile between syncs.
        $withGuild = true;
        Http::fake([
            'eu.api.blizzard.com/*' => function () use (&$withGuild) {
                return Http::response($this->profile($withGuild), 200);
            },
        ]);

        // First sync: profile reports the character is in the guild.
        app()->call([new SyncCharacterData('eu', 'tarren-mill', 'gamma', SyncDepth::Shallow), 'handle']);

        $character = Character::where('name', 'gamma')->where('realm', 'tarren-mill')->firstOrFail();
        $this->assertSame($guild->id, $character->guild_id, 'precondition: character linked to guild');

        // Second sync: the character has left — profile no longer reports a guild.
        $withGuild = false;
        app()->call([new SyncCharacterData('eu', 'tarren-mill', 'gamma', SyncDepth::Shallow), 'handle']);

        $this->assertNull($character->fresh()->guild_id, 'guild_id must be cleared when the character leaves');
    }

    private function profile(bool $withGuild): array
    {
        $profile = [
            'id' => 1,
            'name' => 'Gamma',
            'gender' => ['type' => 'MALE', 'name' => 'Male'],
            'faction' => ['type' => 'HORDE', 'name' => 'Horde'],
            'race' => ['id' => 1, 'name' => 'Human'],
            'character_class' => ['id' => 1, 'name' => 'Warrior'],
            'level' => 90,
            'achievement_points' => 100,
            'average_item_level' => 500,
            'equipped_item_level' => 490,
            'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
        ];

        if ($withGuild) {
            $profile['guild'] = ['name' => 'Echo', 'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill']];
        }

        return $profile;
    }
}
