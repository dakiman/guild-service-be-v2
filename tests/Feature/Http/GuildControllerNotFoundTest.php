<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuildControllerNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

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

    public function test_returns_404_immediately_when_cache_marker_set(): void
    {
        Cache::put('blizzard:not-found:guild:us:illidan:disbanded', true, 60);

        $this->getJson('/api/v1/guilds/us/illidan/disbanded')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Guild not found']);
    }

    public function test_normalizes_uppercase_and_spaces_in_request_url_to_blizzard(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        // First call dispatches the sync; queue=sync runs it inline
        $this->getJson('/api/v1/guilds/us/blades-edge/Attorney%20at%20Law')
            ->assertStatus(202);

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/data/wow/guild/blades-edge/attorney-at-law');
        });

        $this->assertSame(0, Guild::query()->count(), 'no garbage row');
        $this->assertTrue(Cache::has('blizzard:not-found:guild:us:blades-edge:attorney-at-law'));
    }
}
