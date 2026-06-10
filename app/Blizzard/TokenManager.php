<?php

declare(strict_types=1);

namespace App\Blizzard;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;

class TokenManager implements TokenManagerInterface
{
    private const int TTL = 86400 - 300; // 24h minus 5min safety buffer

    public function __construct(
        private readonly BlizzardAuthClient $authClient,
        private readonly CacheManager $cacheManager,
    ) {}

    public function getToken(string $region = 'eu'): string
    {
        return Cache::remember(
            "blizzard:token:{$region}",
            self::TTL,
            fn () => $this->fetchToken($region),
        );
    }

    public function refreshToken(string $region = 'eu'): string
    {
        $lock = Cache::lock("blizzard:token:refresh:{$region}", 10);

        return $lock->block(5, function () use ($region) {
            // Double-check cache after acquiring lock
            $cached = Cache::get("blizzard:token:{$region}");

            if ($cached !== null) {
                return $cached;
            }

            $token = $this->fetchToken($region);

            Cache::put("blizzard:token:{$region}", $token, self::TTL);

            return $token;
        });
    }

    private function fetchToken(string $region): string
    {
        $response = $this->authClient->getToken($region);

        return $response->access_token;
    }
}
