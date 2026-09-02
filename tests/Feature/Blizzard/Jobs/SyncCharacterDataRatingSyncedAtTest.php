<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCharacterDataRatingSyncedAtTest extends TestCase
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

        Bus::fake([SyncCharacterData::class]);
    }

    public function test_shallow_sync_writes_rating_and_rating_synced_at(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        Http::fake([
            // Order matters: the first matching pattern wins.
            'eu.api.blizzard.com/*/mythic-keystone-profile*' => Http::response([
                'current_mythic_rating' => ['rating' => 2846.7, 'color' => ['r' => 255, 'g' => 128, 'b' => 0]],
            ], 200),
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90), 200),
        ]);

        $job = new SyncCharacterData(region: 'eu', realm: 'tarren-mill', name: 'testchar', depth: SyncDepth::Shallow);
        app()->call([$job, 'handle']);

        $character = Character::where('name', 'testchar')->where('realm', 'tarren-mill')->firstOrFail();
        $this->assertSame(2847, $character->mythic_plus_rating);
        $this->assertSame('#ff8000', $character->mythic_plus_rating_color);
        $this->assertSame('2026-09-01 10:00:00', $character->rating_synced_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_sync_without_a_rating_leaves_rating_synced_at_null(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90), 200),
        ]);

        $job = new SyncCharacterData(region: 'eu', realm: 'tarren-mill', name: 'unrated', depth: SyncDepth::Shallow);
        app()->call([$job, 'handle']);

        $character = Character::where('name', 'unrated')->firstOrFail();
        $this->assertNull($character->mythic_plus_rating);
        $this->assertNull($character->rating_synced_at);
    }

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
