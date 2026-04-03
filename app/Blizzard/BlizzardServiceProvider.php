<?php

declare(strict_types=1);

namespace App\Blizzard;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Client\BlizzardUserClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\ServiceProvider;

class BlizzardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BlizzardAuthClient::class, function () {
            return new BlizzardAuthClient(
                clientId: (string) config('blizzard.client.id'),
                clientSecret: (string) config('blizzard.client.secret'),
            );
        });

        $this->app->singleton(TokenManagerInterface::class, function ($app) {
            return new TokenManager(
                authClient: $app->make(BlizzardAuthClient::class),
                cacheManager: $app->make(\Illuminate\Cache\CacheManager::class),
            );
        });

        $this->app->bind(BlizzardProfileClient::class, function ($app) {
            return new BlizzardProfileClient(
                tokenManager: $app->make(TokenManagerInterface::class),
            );
        });

        $this->app->bind(BlizzardGameDataClient::class, function ($app) {
            return new BlizzardGameDataClient(
                tokenManager: $app->make(TokenManagerInterface::class),
            );
        });

        $this->app->singleton(BlizzardUserClient::class, function () {
            return new BlizzardUserClient;
        });
    }

    public function boot(): void
    {
        //
    }
}
