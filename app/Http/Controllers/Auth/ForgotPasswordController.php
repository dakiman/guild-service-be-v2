<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        Password::sendResetLink(
            $request->only('email'),
            function ($user, $token) {
                $url = config('app.frontend_url').'/reset-password?token='.$token.'&email='.urlencode($user->email);

                $user->sendPasswordResetNotification($token);
            }
        );

        return response()->json([
            'message' => 'Password reset link sent.',
        ]);
    }
}
