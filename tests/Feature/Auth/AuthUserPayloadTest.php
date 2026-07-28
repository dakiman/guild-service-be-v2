<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthUserPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_user_payload_carries_characters(): void
    {
        $user = User::factory()->create([
            'email' => 'daki@example.com',
            'password' => 'password',
        ]);
        Character::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'daki@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.user.characters');
    }

    public function test_register_user_payload_carries_an_empty_characters_array(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Daki',
            'email' => 'fresh@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.characters', []);
    }
}
