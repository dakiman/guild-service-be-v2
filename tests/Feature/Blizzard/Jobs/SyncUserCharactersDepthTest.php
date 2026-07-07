<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardUserClient;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncUserCharacters;
use App\Enums\SyncDepth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Battle.net account sync: only endgame characters warrant a Full slice
 * fan-out — a level-20 bank alt gets Standard (profile/gear only).
 */
class SyncUserCharactersDepthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_full_for_endgame_and_standard_for_alts(): void
    {
        Bus::fake([SyncCharacterData::class]);
        config()->set('blizzard.endgame_level', 90);

        $user = User::factory()->create();

        $this->mock(BlizzardUserClient::class, function ($mock) {
            $mock->shouldReceive('getUserInfo')->andReturn(['id' => 123, 'battletag' => 'Test#1234']);
            $mock->shouldReceive('getUserCharacters')->andReturn([
                'wow_accounts' => [[
                    'characters' => [
                        ['name' => 'Mainchar', 'realm' => ['slug' => 'silvermoon'], 'level' => 90],
                        ['name' => 'Bankalt', 'realm' => ['slug' => 'silvermoon'], 'level' => 20],
                        ['name' => 'Mystery', 'realm' => ['slug' => 'silvermoon']],
                    ],
                ]],
            ]);
        });

        $job = new SyncUserCharacters($user, 'eu', 'fake-token');
        app()->call([$job, 'handle']);

        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'mainchar' && $j->depth === SyncDepth::Full);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'bankalt' && $j->depth === SyncDepth::Standard);
        // Missing level in the payload defaults to Standard — the in-job
        // promotion escalates later if the character turns out to be endgame.
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'mystery' && $j->depth === SyncDepth::Standard);
    }
}
