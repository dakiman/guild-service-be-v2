<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCharacterDataShallowPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub token manager so BlizzardProfileClient doesn't hit real OAuth.
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

        Bus::fake([SyncCharacterData::class]);
    }

    public function test_shallow_sync_dispatches_full_for_max_level_never_fully_synced(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(level: 90), 200),
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'newbie',
            depth: SyncDepth::Shallow,
        );

        app()->call([$job, 'handle']);

        // Character should have been created at level 90 with null mythics_synced_at.
        $character = Character::where('name', 'newbie')->where('realm', 'tarren-mill')->first();
        $this->assertNotNull($character);
        $this->assertSame(90, $character->level);
        $this->assertNull($character->mythics_synced_at);

        // Should dispatch a Full sync with forceTeammateCrawl=true.
        Bus::assertDispatched(SyncCharacterData::class, function (SyncCharacterData $dispatched) {
            return $dispatched->region === 'eu'
                && $dispatched->realm === 'tarren-mill'
                && $dispatched->name === 'newbie'
                && $dispatched->depth === SyncDepth::Full
                && $dispatched->forceTeammateCrawl === true;
        });
    }

    public function test_shallow_sync_skips_promotion_for_below_max_level(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(level: 45), 200),
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'lowbie',
            depth: SyncDepth::Shallow,
        );

        app()->call([$job, 'handle']);

        $character = Character::where('name', 'lowbie')->where('realm', 'tarren-mill')->first();
        $this->assertNotNull($character);
        $this->assertSame(45, $character->level);

        // Should NOT dispatch a Full sync for below-max-level character.
        Bus::assertNotDispatched(SyncCharacterData::class, function (SyncCharacterData $dispatched) {
            return $dispatched->depth === SyncDepth::Full;
        });
    }

    public function test_shallow_sync_skips_promotion_when_already_fully_synced(): void
    {
        // Pre-create the character as already fully synced.
        Character::factory()->create([
            'name' => 'veteran',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 90,
            'mythics_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(level: 90, name: 'Veteran'), 200),
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'veteran',
            depth: SyncDepth::Shallow,
        );

        app()->call([$job, 'handle']);

        // Should NOT dispatch a Full sync since mythics_synced_at is already set.
        Bus::assertNotDispatched(SyncCharacterData::class, function (SyncCharacterData $dispatched) {
            return $dispatched->depth === SyncDepth::Full;
        });
    }

    /**
     * Minimal Blizzard profile JSON satisfying CharacterProfileMapper.
     */
    private function profileResponse(int $level, string $name = 'Testchar'): array
    {
        return [
            'id' => 1,
            'name' => $name,
            'gender' => ['type' => 'MALE', 'name' => 'Male'],
            'faction' => ['type' => 'ALLIANCE', 'name' => 'Alliance'],
            'race' => ['id' => 1, 'name' => 'Human'],
            'character_class' => ['id' => 1, 'name' => 'Warrior'],
            'level' => $level,
            'achievement_points' => 100,
            'average_item_level' => 500,
            'equipped_item_level' => 490,
            'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
        ];
    }
}
