<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncGuildDataNotFoundTest extends TestCase
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
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(
                ['code' => 404, 'type' => 'BLZWEBAPI00000404', 'detail' => 'Not Found'],
                404
            ),
        ]);

        SyncGuildData::dispatchSync('us', 'illidan', 'zzz-disbanded-guild');

        $this->assertTrue(
            Cache::has('blizzard:not-found:guild:us:illidan:zzz-disbanded-guild'),
            'expected not-found cache marker to be set'
        );
        $this->assertSame(0, Guild::query()->count(), 'no guild row should be persisted on 404');
    }
}
