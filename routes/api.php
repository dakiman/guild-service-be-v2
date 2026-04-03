<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $checks = [];

    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Throwable) {
        $checks['database'] = 'failed';
    }

    try {
        \Illuminate\Support\Facades\Redis::ping();
        $checks['redis'] = 'ok';
    } catch (\Throwable) {
        $checks['redis'] = 'failed';
    }

    $allOk = ! in_array('failed', $checks);

    return response()->json($checks, $allOk ? 200 : 503);
});

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/forgot', ForgotPasswordController::class);
    Route::post('/password/reset', [ResetPasswordController::class, 'reset']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});

/*
|--------------------------------------------------------------------------
| Character Routes
|--------------------------------------------------------------------------
*/
Route::get('/characters/popular', function () {
    // Placeholder
})->name('characters.popular');

Route::get('/characters/{region}/{realm}/{character}', function () {
    // Placeholder
})->name('characters.show');

Route::patch('/characters/{character}/recruitment', function () {
    // Placeholder
})->middleware('auth:sanctum')->name('characters.recruitment');

/*
|--------------------------------------------------------------------------
| Guild Routes
|--------------------------------------------------------------------------
*/
Route::get('/guilds/popular', function () {
    // Placeholder
})->name('guilds.popular');

Route::get('/guilds/{region}/{realm}/{guild}', function () {
    // Placeholder
})->name('guilds.show');

/*
|--------------------------------------------------------------------------
| Blizzard OAuth Route
|--------------------------------------------------------------------------
*/
Route::post('/{region}/blizzard-oauth', function () {
    // Placeholder
})->middleware('auth:sanctum')->name('blizzard.oauth');
