<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BlizzardController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\GuildController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $checks = [];

    try {
        DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (Throwable) {
        $checks['database'] = 'failed';
    }

    try {
        Redis::ping();
        $checks['redis'] = 'ok';
    } catch (Throwable) {
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
Route::get('/characters/popular', [CharacterController::class, 'popular'])->name('characters.popular');
Route::get('/characters/{region}/{realm}/{character}', [CharacterController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('characters.show');
Route::patch('/characters/{character}/recruitment', [CharacterController::class, 'toggleRecruitment'])
    ->middleware('auth:sanctum')
    ->name('characters.recruitment');

/*
|--------------------------------------------------------------------------
| Guild Routes
|--------------------------------------------------------------------------
*/
Route::get('/guilds/popular', [GuildController::class, 'popular'])->name('guilds.popular');
Route::get('/guilds/{region}/{realm}/{guild}', [GuildController::class, 'show'])->name('guilds.show');

/*
|--------------------------------------------------------------------------
| Blizzard OAuth Route
|--------------------------------------------------------------------------
*/
Route::post('/{region}/blizzard-oauth', [BlizzardController::class, 'handleCode'])
    ->middleware('auth:sanctum')
    ->name('blizzard.oauth');
