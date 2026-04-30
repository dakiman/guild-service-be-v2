<?php

use App\Blizzard\Exceptions\BlizzardApiException;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Blizzard\Jobs\ProactiveSyncCharacters;
use App\Blizzard\Jobs\ProactiveSyncGuilds;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('blizzard:token')->twiceDaily()->withoutOverlapping();
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        $schedule->job(new ProactiveSyncCharacters(tier: 1))->everyThirtyMinutes()->withoutOverlapping();
        $schedule->job(new ProactiveSyncCharacters(tier: 2))->everyTwoHours()->withoutOverlapping();
        $schedule->job(new ProactiveSyncGuilds)->dailyAt('04:00')->withoutOverlapping();
        $schedule->command('queue:prune-batches --hours=48')->daily();
        $schedule->command('queue:prune-failed --hours=168')->daily();
        $schedule->command('blizzard:sync-game-data')
            ->weeklyOn(0, '03:00') // Sunday 03:00 UTC
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (BlizzardNotFoundException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        });

        $exceptions->renderable(function (BlizzardApiException $e) {
            return response()->json([
                'message' => 'Blizzard services are temporarily unavailable',
            ], 503);
        });
    })->create();
