<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BlizzardController;
use App\Http\Controllers\CharacterAchievementsController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterStatsController;
use App\Http\Controllers\GameDataController;
use App\Http\Controllers\GuildController;
use App\Http\Controllers\GuildStatsController;
use App\Http\Controllers\RaidKillStatsController;
use App\Http\Controllers\TopKeysController;
use App\Http\Controllers\TopRunsController;
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
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/password/forgot', ForgotPasswordController::class)->middleware('throttle:3,1');
    Route::post('/password/reset', [ResetPasswordController::class, 'reset'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});

$regions = config('blizzard.regions', ['eu', 'us', 'kr', 'tw']);

/*
|--------------------------------------------------------------------------
| Character Routes
|--------------------------------------------------------------------------
*/
Route::get('/characters/popular', [CharacterController::class, 'popular'])->name('characters.popular');
Route::get('/characters/suggest', [CharacterController::class, 'suggest'])
    ->middleware('throttle:60,1')
    ->name('characters.suggest');
Route::get('/characters/{region}/{realm}/{character}', [CharacterController::class, 'show'])
    ->whereIn('region', $regions)
    ->middleware('throttle:character-lookup')
    ->name('characters.show');
Route::get('/characters/{region}/{realm}/{character}/achievements', [CharacterAchievementsController::class, 'index'])
    ->whereIn('region', $regions)
    ->middleware('throttle:30,1')
    ->name('characters.achievements');
Route::patch('/characters/{character}/recruitment', [CharacterController::class, 'toggleRecruitment'])
    ->middleware('auth:sanctum')
    ->name('characters.recruitment');

/*
|--------------------------------------------------------------------------
| Stats Routes
|--------------------------------------------------------------------------
*/
Route::get('/stats/characters/top-runs', TopRunsController::class)
    ->middleware('throttle:30,1')
    ->name('stats.characters.top-runs');
Route::get('/stats/characters/top-keys', TopKeysController::class)
    ->middleware('throttle:30,1')
    ->name('stats.characters.top-keys');
Route::get('/stats/characters/raid-kills', RaidKillStatsController::class)
    ->middleware('throttle:30,1')
    ->name('stats.characters.raid-kills');
Route::get('/stats/characters', CharacterStatsController::class)
    ->middleware('throttle:30,1')
    ->name('stats.characters');

/*
|--------------------------------------------------------------------------
| Guild Routes
|--------------------------------------------------------------------------
*/
Route::get('/guilds/popular', [GuildController::class, 'popular'])->name('guilds.popular');
Route::get('/guilds/suggest', [GuildController::class, 'suggest'])
    ->middleware('throttle:60,1')
    ->name('guilds.suggest');
Route::get('/guilds/discover', [GuildController::class, 'discover'])
    ->middleware('throttle:30,1')
    ->name('guilds.discover');
Route::get('/guilds/{region}/{realm}/{guild}/stats', GuildStatsController::class)
    ->whereIn('region', $regions)
    ->middleware('throttle:30,1')
    ->name('guilds.stats');
Route::get('/guilds/{region}/{realm}/{guild}', [GuildController::class, 'show'])
    ->whereIn('region', $regions)
    ->middleware('throttle:guild-lookup')
    ->name('guilds.show');

/*
|--------------------------------------------------------------------------
| Game Data Routes (public, long-cacheable)
|--------------------------------------------------------------------------
*/
Route::get('/game-data/raid-instances', [GameDataController::class, 'raidInstances'])
    ->name('game-data.raid-instances');
Route::get('/game-data/mythic-keystone-dungeons', [GameDataController::class, 'mythicKeystoneDungeons'])
    ->name('game-data.mythic-keystone-dungeons');
Route::get('/game-data/talent-trees/{treeId}/{specId}', [GameDataController::class, 'talentTree'])
    ->whereNumber(['treeId', 'specId'])
    ->name('game-data.talent-tree');
Route::get('/game-data/realms', [GameDataController::class, 'realms'])
    ->name('game-data.realms');

/*
|--------------------------------------------------------------------------
| Blizzard OAuth Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:10,1'])
    ->whereIn('region', config('blizzard.regions', ['eu', 'us', 'kr', 'tw']))
    ->group(function () {
        Route::post('/{region}/blizzard-oauth/state', [BlizzardController::class, 'state'])
            ->name('blizzard.oauth.state');
        Route::post('/{region}/blizzard-oauth', [BlizzardController::class, 'handleCode'])
            ->name('blizzard.oauth');
    });
