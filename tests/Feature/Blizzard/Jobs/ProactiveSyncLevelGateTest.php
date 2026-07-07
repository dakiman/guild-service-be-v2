<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\ProactiveSyncCharacters;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Proactive re-syncs are endgame-only: popular sub-max alts must not consume
 * background API budget on either tier.
 */
class ProactiveSyncLevelGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config()->set('blizzard.endgame_level', 90);
    }

    private function makePopular(string $name, int $level): Character
    {
        return Character::factory()->create([
            'region' => 'eu', 'realm' => 'silvermoon', 'name' => $name,
            'game_version' => 'retail',
            'level' => $level,
            'num_of_searches' => 10,
            'last_searched_at' => now()->subDay(),
            'last_login_at' => now()->subDay(),
        ]);
    }

    public function test_tier1_skips_sub_endgame_character(): void
    {
        $this->makePopular('popularalt', 89);

        (new ProactiveSyncCharacters(tier: 1))->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'popularalt');
    }

    public function test_tier2_skips_sub_endgame_character(): void
    {
        $this->makePopular('popularalt', 89);

        (new ProactiveSyncCharacters(tier: 2))->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'popularalt');
    }

    public function test_tier1_still_dispatches_endgame_character(): void
    {
        $this->makePopular('popularmain', 90);

        (new ProactiveSyncCharacters(tier: 1))->handle();

        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'popularmain');
    }
}
