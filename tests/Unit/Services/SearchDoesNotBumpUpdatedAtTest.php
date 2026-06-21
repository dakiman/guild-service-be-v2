<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Character;
use App\Models\Guild;
use App\Services\CharacterService;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1.1: recording a search must NOT bump `updated_at`. `updated_at` is the
 * profile-sync clock that `isStale()` reads — if `increment()` auto-touches it,
 * every search makes a popular entity look freshly synced and the Standard
 * re-sync never fires (and X-Data-Staleness / freshness meta lie).
 */
class SearchDoesNotBumpUpdatedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_search_increments_counter_without_touching_updated_at(): void
    {
        Queue::fake();

        $synced = Carbon::parse('2026-06-01 12:00:00');
        Carbon::setTestNow($synced);

        $character = Character::factory()->create([
            'name' => 'cirna',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'num_of_searches' => 3,
        ]);

        // Five days later, someone searches for the character.
        Carbon::setTestNow($synced->copy()->addDays(5));

        app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'cirna');

        $character->refresh();

        // The visit is recorded...
        $this->assertSame(4, $character->num_of_searches);
        $this->assertTrue($character->last_searched_at->equalTo(now()));
        // ...but the profile-sync clock is untouched.
        $this->assertTrue(
            $character->updated_at->equalTo($synced),
            "updated_at was bumped to {$character->updated_at}, expected {$synced}",
        );
    }

    public function test_guild_search_increments_counter_without_touching_updated_at(): void
    {
        Queue::fake();

        $synced = Carbon::parse('2026-06-01 12:00:00');
        Carbon::setTestNow($synced);

        $guild = Guild::factory()->create([
            'name' => 'noignore',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'num_of_searches' => 3,
        ]);

        Carbon::setTestNow($synced->copy()->addDays(5));

        app(GuildService::class)->getByIdentity('eu', 'the-maelstrom', 'noignore');

        $guild->refresh();

        $this->assertSame(4, $guild->num_of_searches);
        $this->assertTrue($guild->last_searched_at->equalTo(now()));
        $this->assertTrue(
            $guild->updated_at->equalTo($synced),
            "updated_at was bumped to {$guild->updated_at}, expected {$synced}",
        );
    }
}
