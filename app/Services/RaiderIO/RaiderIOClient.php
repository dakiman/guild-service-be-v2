<?php

declare(strict_types=1);

namespace App\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class RaiderIOClient
{
    /**
     * Yields up to $limit SeedGuildRef rows for a region.
     *
     * @return Generator<int, SeedGuildRef>
     */
    public function topGuilds(string $region, int $limit): Generator
    {
        $raid = $this->currentRaidSlug();
        $perPage = 20;
        $pagesNeeded = (int) ceil($limit / $perPage);
        $yielded = 0;

        for ($page = 0; $page < $pagesNeeded && $yielded < $limit; $page++) {
            $response = $this->get('/raiding/raid-rankings', [
                'raid' => $raid,
                'difficulty' => 'mythic',
                'region' => $region,
                'limit' => $perPage,
                'page' => $page,
            ]);

            $rows = $response->json('raidRankings') ?? [];

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                if ($yielded >= $limit) {
                    return;
                }
                $name = $row['guild']['name'] ?? null;
                $realmSlug = $row['guild']['realm']['slug'] ?? null;
                $regionSlug = $row['guild']['region']['slug'] ?? $region;
                if ($name === null || $realmSlug === null) {
                    continue;
                }
                $yielded++;
                yield new SeedGuildRef(region: $regionSlug, realmSlug: $realmSlug, name: $name);
            }
        }
    }

    protected function currentRaidSlug(): string
    {
        // raider.io's raid-rankings endpoint keys raids by tier slug (e.g. "tier-mn-1"
        // for Midnight tier 1), NOT by raid instance slug. Bump per tier rotation.
        return (string) config('raiderio.current_raid_tier', 'tier-mn-1');
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('raiderio.base_url'))
            ->acceptJson()
            ->timeout(15);
    }

    protected function skipRedisThrottle(): bool
    {
        return ! $this->backoffSleepEnabled();
    }

    protected function skipBackoffSleep(): bool
    {
        return ! $this->backoffSleepEnabled();
    }

    protected function backoffSleepEnabled(): bool
    {
        $value = $_SERVER['RAIDERIO_BACKOFF_SLEEP_ENABLED']
            ?? $_ENV['RAIDERIO_BACKOFF_SLEEP_ENABLED']
            ?? getenv('RAIDERIO_BACKOFF_SLEEP_ENABLED');

        if ($value === false || $value === null || $value === '') {
            return true;
        }

        return ! in_array(strtolower((string) $value), ['0', 'false', 'no', 'off'], true);
    }

    protected function get(string $path, array $query): Response
    {
        if ($this->skipRedisThrottle()) {
            return $this->doGet($path, $query);
        }

        return Redis::throttle('raiderio:requests')
            ->allow((int) config('raiderio.throttle.per_minute'))
            ->every(60)
            ->block(30)
            ->then(
                fn () => $this->doGet($path, $query),
                function () use ($path) {
                    throw new RaiderIOException("raiderio: throttle timeout for $path");
                }
            );
    }

    protected function doGet(string $path, array $query): Response
    {
        $attempt429 = 0;
        $attempt5xx = 0;
        $backoffSeconds = [1, 4, 10];

        while (true) {
            $response = $this->http()->get($path, $query);

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();

            if ($status === 429 && $attempt429 < 1) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 60);
                if ($retryAfter > 0 && ! $this->skipBackoffSleep()) {
                    sleep($retryAfter);
                }
                $attempt429++;

                continue;
            }

            if ($status >= 500 && $attempt5xx < count($backoffSeconds)) {
                $sleep = $backoffSeconds[$attempt5xx];
                if ($sleep > 0 && ! $this->skipBackoffSleep()) {
                    sleep($sleep);
                }
                $attempt5xx++;

                continue;
            }

            throw new RaiderIOException(
                sprintf('raider.io returned HTTP %d for %s', $status, $path)
            );
        }
    }
}
