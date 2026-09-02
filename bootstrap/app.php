<?php

use App\Blizzard\Exceptions\BlizzardApiException;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Blizzard\Jobs\ProactiveSyncCharacters;
use App\Http\Middleware\ForceJsonResponse;
use App\Jobs\WarmCharacterStats;
use App\Jobs\WarmRaidKillStats;
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
        $schedule->job(new WarmCharacterStats)->hourly()->withoutOverlapping();
        $schedule->job(new WarmRaidKillStats)->everyThirtyMinutes()->withoutOverlapping();
        $schedule->job(new ProactiveSyncCharacters(tier: 1))->dailyAt('05:00')->withoutOverlapping();
        $schedule->job(new ProactiveSyncCharacters(tier: 2))->weeklyOn(0, '06:00')->withoutOverlapping();
        $schedule->command('queue:prune-batches --hours=48')->daily();
        $schedule->command('queue:prune-failed --hours=168')->daily();
        $schedule->command('blizzard:sync-game-data')
            ->weeklyOn(0, '03:00') // Sunday 03:00 UTC
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('raiderio:crawl-runs')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('blizzard:sync-game-data periods')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('blizzard:seed-ladders')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('ranks:materialize')
            ->dailyAt('04:00') // after seed-ladders 03:30, before meta:warm 04:30
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('ratings:refresh')
            ->dailyAt('06:30') // after the ladder fan-out, before organic traffic
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('meta:warm')
            ->everyThirtyMinutes()
            ->between('4:30', '12:00')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('raiderio:seed --phase=all')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('raids:prune-legacy')
            ->monthlyOn(1, '04:30')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('ladder:prune')
            ->weeklyOn(4, '09:00') // Thu — after both regions' resets and the finalize crawls
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
