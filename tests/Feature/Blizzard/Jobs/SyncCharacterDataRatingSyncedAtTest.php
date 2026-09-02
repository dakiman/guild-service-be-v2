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

    /** @param list<int> $seasons */
    private function keystoneProfile(?float $rating, array $seasons): array
    {
        return [
            'current_mythic_rating' => $rating === null ? null : ['rating' => $rating, 'color' => ['r' => 255, 'g' => 128, 'b' => 0]],
            'seasons' => array_map(fn (int $id) => ['key' => ['href' => "https://x/{$id}"], 'id' => $id], $seasons),
        ];
    }

    private function staleRated(string $name): Character
    {
        return Character::factory()->create([
            'name' => $name, 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
            'mythic_plus_rating' => 2500, 'mythic_plus_rating_color' => '#a335ee',
            'rating_season_id' => null, 'rating_synced_at' => '2026-08-01 00:00:00',
        ]);
    }

    private function shallowSync(string $name): Character
    {
        $job = new SyncCharacterData(region: 'eu', realm: 'tarren-mill', name: $name, depth: SyncDepth::Shallow);
        app()->call([$job, 'handle']);

        return Character::where('name', $name)->where('realm', 'tarren-mill')->firstOrFail();
    }

    public function test_shallow_sync_writes_rating_with_its_season_and_stamp(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        Http::fake([
            // Order matters: the first matching pattern wins.
            'eu.api.blizzard.com/*/mythic-keystone-profile*' => Http::response($this->keystoneProfile(2846.7, [15, 17]), 200),
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90), 200),
        ]);

        $character = $this->shallowSync('testchar');

        $this->assertSame(2847, $character->mythic_plus_rating);
        $this->assertSame('#ff8000', $character->mythic_plus_rating_color);
        $this->assertSame(17, $character->rating_season_id);
        $this->assertSame('2026-09-01 10:00:00', $character->rating_synced_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_season_tag_is_the_newest_season_played(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*/mythic-keystone-profile*' => Http::response($this->keystoneProfile(2900.0, [15, 17, 18]), 200),
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90), 200),
        ]);

        $this->assertSame(18, $this->shallowSync('current')->rating_season_id);
    }

    public function test_sync_when_mythic_profile_unavailable_touches_nothing(): void
    {
        $this->staleRated('existing');
        Http::fake([
            'eu.api.blizzard.com/*/mythic-keystone-profile*' => Http::response([], 404),
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90, 'Existing'), 200),
        ]);

        $character = $this->shallowSync('existing');

        $this->assertSame(2500, $character->mythic_plus_rating);
        $this->assertSame('#a335ee', $character->mythic_plus_rating_color);
        $this->assertNull($character->rating_season_id);
        $this->assertSame('2026-08-01 00:00:00', $character->rating_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_absent_rating_with_season_history_keeps_the_rating_and_tags_it(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->staleRated('stalerated');
        Http::fake([
            'eu.api.blizzard.com/*/mythic-keystone-profile*' => Http::response($this->keystoneProfile(null, [15, 17]), 200),
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90, 'Stalerated'), 200),
        ]);

        $character = $this->shallowSync('stalerated');

        $this->assertSame(2500, $character->mythic_plus_rating);
        $this->assertSame('#a335ee', $character->mythic_plus_rating_color);
        $this->assertSame(17, $character->rating_season_id);
        $this->assertSame('2026-09-01 10:00:00', $character->rating_synced_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_absent_rating_with_no_season_history_clears_the_rating(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->staleRated('nohistory');
        Http::fake([
            'eu.api.blizzard.com/*/mythic-keystone-profile*' => Http::response($this->keystoneProfile(null, []), 200),
            'eu.api.blizzard.com/*' => Http::response($this->profileResponse(90, 'Nohistory'), 200),
        ]);

        $character = $this->shallowSync('nohistory');

        $this->assertNull($character->mythic_plus_rating);
        $this->assertNull($character->mythic_plus_rating_color);
        $this->assertNull($character->rating_season_id);
        $this->assertSame('2026-09-01 10:00:00', $character->rating_synced_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
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
