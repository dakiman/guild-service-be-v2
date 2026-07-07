<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Post level-gating, every sub-endgame character has null slice timestamps by
 * design — the backfill command must not treat them as backfill targets.
 */
class BackfillSlicesLevelGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_skips_sub_endgame_characters(): void
    {
        Bus::fake();
        config()->set('blizzard.endgame_level', 90);

        Character::factory()->create([
            'name' => 'lowbie', 'realm' => 'silvermoon', 'region' => 'eu',
            'game_version' => 'retail', 'level' => 45,
            'mythics_synced_at' => null,
        ]);
        Character::factory()->create([
            'name' => 'mainchar', 'realm' => 'silvermoon', 'region' => 'eu',
            'game_version' => 'retail', 'level' => 90,
            'mythics_synced_at' => null,
        ]);

        $this->artisan('blizzard:backfill-slices')->assertSuccessful();

        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'mainchar');
        Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'lowbie');
    }
}
