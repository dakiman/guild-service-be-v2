<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Per-entity `?refresh=1` cooldown. Grant is a single atomic `Cache::add()`
 * (Redis SET NX) so concurrent requests for the same entity can only claim
 * the grant once; the cached value is the expiry epoch so `status()` needs
 * no separate TTL probe.
 */
final class RefreshCooldown
{
    public static function key(string $type, string $region, string $realm, string $name): string
    {
        return "refresh-cooldown:{$type}:{$region}:{$realm}:{$name}";
    }

    public static function attempt(string $type, string $region, string $realm, string $name): bool
    {
        $ttl = (int) config('blizzard.refresh_cooldown', 300);

        return Cache::add(self::key($type, $region, $realm, $name), now()->addSeconds($ttl)->getTimestamp(), $ttl);
    }

    /** @return array{available: bool, available_at: ?string, cooldown_seconds: int} */
    public static function status(string $type, string $region, string $realm, string $name): array
    {
        $expiresAt = Cache::get(self::key($type, $region, $realm, $name));

        return [
            'available' => $expiresAt === null,
            'available_at' => $expiresAt !== null ? Carbon::createFromTimestamp((int) $expiresAt)->toIso8601String() : null,
            'cooldown_seconds' => (int) config('blizzard.refresh_cooldown', 300),
        ];
    }
}
