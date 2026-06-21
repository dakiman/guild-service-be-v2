<?php

declare(strict_types=1);

namespace App\Blizzard;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;

class TokenManager implements TokenManagerInterface
{
    // 24h minus a 5-min safety buffer — the hard ceiling applied when Blizzard's
    // expires_in is absent or longer than a day.
    private const int MAX_TTL = 86400 - 300;

    private const int SAFETY_BUFFER = 300;

    public function __construct(
        private readonly BlizzardAuthClient $authClient,
        private readonly CacheManager $cacheManager,
    ) {}

    public function getToken(string $region = 'eu'): string
    {
        return Cache::get("blizzard:token:{$region}") ?? $this->refreshToken($region);
    }

    public function refreshToken(string $region = 'eu'): string
    {
        // Snapshot before locking so a *forced* refresh (the twice-daily job)
        // still fetches — the old code short-circuited on any cached value,
        // making the scheduled refresh a no-op. Concurrent callers still dedupe:
        // whoever finds a token that changed during their wait reuses it. (P1.5)
        $before = Cache::get("blizzard:token:{$region}");
        $lock = Cache::lock("blizzard:token:refresh:{$region}", 10);

        return $lock->block(5, function () use ($region, $before) {
            $current = Cache::get("blizzard:token:{$region}");
            if ($current !== null && $current !== $before) {
                return $current;
            }

            $response = $this->authClient->getToken($region);
            Cache::put("blizzard:token:{$region}", $response->access_token, $this->ttlFor($response));

            return $response->access_token;
        });
    }

    /**
     * Honor Blizzard's expires_in (minus a safety buffer) instead of assuming a
     * full 24h, so a shorter-lived token is evicted before it 401s. (P1.5)
     */
    private function ttlFor(object $response): int
    {
        $expiresIn = isset($response->expires_in)
            ? (int) $response->expires_in
            : self::MAX_TTL + self::SAFETY_BUFFER;

        return max(60, min(self::MAX_TTL, $expiresIn - self::SAFETY_BUFFER));
    }
}
