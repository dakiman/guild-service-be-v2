<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_email_returns_same_message_and_sends_no_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'missing@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If that email exists, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_known_email_returns_same_message_and_sends_reset_with_frontend_url(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'http://localhost:8092');

        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'known@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If that email exists, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl ?? '';

            return str_starts_with($url, 'http://localhost:8092/reset-password?')
                && str_contains($url, 'token=')
                && str_contains($url, 'email=known%40example.com');
        });
    }

    public function test_invalid_email_format_still_validates(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'not-an-email',
        ])->assertUnprocessable();
    }
}
