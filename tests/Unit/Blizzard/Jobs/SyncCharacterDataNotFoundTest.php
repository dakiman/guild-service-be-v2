<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCharacterDataNotFoundTest extends TestCase
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
    }

    public function test_404_writes_cache_marker_and_does_not_persist_a_row(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(
                ['code' => 404, 'type' => 'BLZWEBAPI00000404', 'detail' => 'Not Found'],
                404
            ),
        ]);

        SyncCharacterData::dispatchSync('eu', 'the-maelstrom', 'zzzzzznonexistent');

        $this->assertTrue(
            Cache::has('blizzard:not-found:character:eu:the-maelstrom:zzzzzznonexistent'),
            'expected not-found cache marker to be set'
        );
        $this->assertSame(0, Character::query()->count(), 'no character row should be persisted on 404');
    }
}
