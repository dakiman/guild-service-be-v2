<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicRouteThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
        Bus::fake();
    }

    public function test_character_achievements_route_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/characters/eu/the-maelstrom/melaniya/achievements');
        }

        $this->getJson('/api/v1/characters/eu/the-maelstrom/melaniya/achievements')
            ->assertStatus(429);
    }

    public function test_guild_show_route_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');
        }

        $this->getJson('/api/v1/guilds/eu/tarren-mill/echo')
            ->assertStatus(429);
    }

    public function test_login_route_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_unsupported_region_returns_404(): void
    {
        $this->getJson('/api/v1/characters/zz/the-maelstrom/melaniya')
            ->assertNotFound();
    }
}
