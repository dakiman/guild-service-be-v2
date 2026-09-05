<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterSummaryRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_user_characters_carry_recruitment_flag(): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id, 'recruitment' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/user');

        $response->assertOk()->assertJsonPath('data.characters.0.recruitment', true);
    }
}
