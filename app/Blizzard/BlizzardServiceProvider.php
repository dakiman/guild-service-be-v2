<?php

declare(strict_types=1);

namespace App\Blizzard;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Client\BlizzardUserClient;
use App\Blizzard\Client\GameDataClientFactory;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Support\BlizzardHttpThrottle;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

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
                cacheManager: $app->make(CacheManager::class),
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

        $this->app->singleton(GameDataClientFactory::class);

        $this->app->singleton(BlizzardUserClient::class, function () {
            return new BlizzardUserClient;
        });
    }

    public function boot(): void
    {
        // Request-level rate limit: one throttle slot per real HTTP request to
        // api.blizzard.com. Hooking the client factory covers everything —
        // request()-built calls, Http::pool() fan-outs, the direct Http calls
        // in BlizzardGameDataClient, and per-attempt 5xx retries — while OAuth
        // traffic (battle.net host) passes untouched.
        Http::globalRequestMiddleware(function (RequestInterface $request) {
            if (str_ends_with($request->getUri()->getHost(), '.api.blizzard.com')) {
                $this->app->make(BlizzardHttpThrottle::class)->acquire();
            }

            return $request;
        });
    }
}
