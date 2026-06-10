<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('blizzard.oauth.redirect_uris', ['http://localhost:5173/blizzard-oauth']);
        config()->set('blizzard.oauth.state_ttl', 600);
    }

    public function test_authenticated_user_can_mint_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth/state', [
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
        ]);

        $response->assertOk()->assertJsonStructure(['state', 'expires_in']);

        $state = $response->json('state');
        $this->assertIsString($state);
        $this->assertTrue(Cache::has("blizzard:oauth-state:{$user->id}:eu:{$state}"));
    }

    public function test_state_mint_rejects_untrusted_redirect_uri(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth/state', [
            'redirectUri' => 'https://evil.example/callback',
        ])->assertUnprocessable();
    }

    public function test_state_mint_requires_auth(): void
    {
        $this->postJson('/api/v1/eu/blizzard-oauth/state', [
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
        ])->assertUnauthorized();
    }

    public function test_code_exchange_requires_state_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
        ])->assertUnprocessable();
    }

    public function test_code_exchange_rejects_unknown_state(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
            'state' => str_repeat('a', 64),
        ])->assertUnprocessable();
    }

    public function test_code_exchange_rejects_untrusted_redirect_uri(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'https://evil.example/callback',
            'state' => str_repeat('a', 64),
        ])->assertUnprocessable();
    }

    public function test_code_exchange_consumes_cached_state_once(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $state = str_repeat('a', 64);
        Cache::put(
            "blizzard:oauth-state:{$user->id}:eu:{$state}",
            ['redirectUri' => 'http://localhost:5173/blizzard-oauth'],
            600
        );

        $authClient = \Mockery::mock(BlizzardAuthClient::class);
        $authClient->shouldReceive('getOauthAccessToken')
            ->once()
            ->with('eu', 'abc', 'http://localhost:5173/blizzard-oauth')
            ->andReturn((object) ['access_token' => 'token']);
        $this->app->instance(BlizzardAuthClient::class, $authClient);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
            'state' => $state,
        ])->assertAccepted();

        $this->assertFalse(
            Cache::has("blizzard:oauth-state:{$user->id}:eu:{$state}"),
            'State must be single-use'
        );
    }
}
