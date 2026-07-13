<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auto-discover: when SyncCharacterData writes a brand-new Guild shell
 * (Guild::firstOrCreate → wasRecentlyCreated), it must dispatch SyncGuildData
 * onto the Discovery lane — never the user-lookup lane, and never with the
 * roster fan-out forced on (that's the raider.io seeder's job only). See
 * CLAUDE.md "Auto-discover guild" and app/Blizzard/Jobs/SyncCharacterData.php
 * handle() ~line 313-327.
 */
class SyncCharacterDataAutoDiscoverLaneTest extends TestCase
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

        // Only SyncGuildData is faked: the character sync itself must run
        // for real so the guild shell actually gets created and
        // wasRecentlyCreated fires. Character is sub-endgame (level 20) so
        // the Full-promotion self::dispatch(SyncCharacterData) branch never
        // triggers and doesn't need faking too.
        Bus::fake([SyncGuildData::class]);
    }

    public function test_auto_discover_dispatches_sync_guild_data_on_discovery_lane(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->profile(), 200),
        ]);

        app()->call([new SyncCharacterData('eu', 'tarren-mill', 'newbie', SyncDepth::Shallow), 'handle']);

        Bus::assertDispatched(
            SyncGuildData::class,
            fn (SyncGuildData $job) => $job->origin === SyncOrigin::Discovery
                && $job->queue === 'blizzard-background'
                && $job->forceRosterFanout === false,
        );
    }

    private function profile(): array
    {
        return [
            'id' => 1,
            'name' => 'Newbie',
            'gender' => ['type' => 'MALE', 'name' => 'Male'],
            'faction' => ['type' => 'HORDE', 'name' => 'Horde'],
            'race' => ['id' => 1, 'name' => 'Human'],
            'character_class' => ['id' => 1, 'name' => 'Warrior'],
            'level' => 20,
            'achievement_points' => 10,
            'average_item_level' => 50,
            'equipped_item_level' => 45,
            'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
            'guild' => ['name' => 'Freshly Founded', 'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill']],
        ];
    }
}
