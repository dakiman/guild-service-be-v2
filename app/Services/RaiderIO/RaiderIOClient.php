<?php

declare(strict_types=1);

namespace App\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\DTO\SeedRunRef;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use App\Services\RaiderIO\Exceptions\RaiderIOThrottledException;
use App\Support\BlizzardIdentity;
use App\Support\Seasons;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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
                // Canonicalize at the boundary so downstream byIdentity() probes and
                // job dispatches use the same form the rest of the app uses (controllers
                // call BlizzardIdentity on user input). Guild names go through realm()
                // (Str::slug) per GuildController; realm slugs already canonical.
                yield new SeedGuildRef(
                    region: $regionSlug,
                    realmSlug: BlizzardIdentity::realm($realmSlug),
                    name: BlizzardIdentity::realm($name),
                );
            }
        }
    }

    /**
     * Yields top mythic+ runs for the given region+season.
     * `pages` is a fixed page count (1 page = 20 runs from raider.io).
     * `$dungeon` (a dungeon slug) filters the ladder to that single dungeon;
     * null keeps the unfiltered all-dungeons ladder.
     *
     * @return Generator<int, SeedRunRef>
     */
    public function topRuns(string $region, string $season, int $pages, ?string $dungeon = null): Generator
    {
        for ($page = 0; $page < $pages; $page++) {
            $query = [
                'season' => $season,
                'region' => $region,
                'page' => $page,
            ];
            if ($dungeon !== null) {
                $query['dungeon'] = $dungeon;
            }

            $response = $this->get('/mythic-plus/runs', $query);

            $rankings = $response->json('rankings') ?? [];

            if ($rankings === []) {
                return;
            }

            foreach ($rankings as $ranking) {
                $run = $ranking['run'] ?? null;
                if ($run === null) {
                    continue;
                }
                $keystoneRunId = $run['keystone_run_id'] ?? null;
                if (! is_int($keystoneRunId)) {
                    continue;
                }

                $members = [];
                foreach (($run['roster'] ?? []) as $rosterEntry) {
                    $character = $rosterEntry['character'] ?? null;
                    if ($character === null) {
                        continue;
                    }
                    $name = $character['name'] ?? null;
                    $realmSlug = $character['realm']['slug'] ?? null;
                    $regionSlug = $character['region']['slug'] ?? $region;
                    if ($name === null || $realmSlug === null) {
                        continue;
                    }
                    // Canonicalize: character names get lowercased (UTF-8-safe), realm slugs
                    // get Str::slug. Matches CharacterController so byIdentity() probes hit.
                    $members[] = new SeedCharacterRef(
                        region: $regionSlug,
                        realmSlug: BlizzardIdentity::realm($realmSlug),
                        name: BlizzardIdentity::name($name),
                    );
                }

                yield new SeedRunRef(
                    keystoneRunId: $keystoneRunId,
                    region: $region,
                    members: $members,
                );
            }
        }
    }

    /**
     * Fetch raider.io's mythic-plus static-data for a given expansion.
     * Returns the raw decoded JSON. Used for icon backfill — Blizzard does
     * not expose dungeon media URLs, but raider.io does at
     * `seasons[].dungeons[].icon_url` keyed by `challenge_mode_id`.
     *
     * @return array<string, mixed>
     */
    public function mythicPlusStaticData(int $expansionId): array
    {
        $response = $this->get('/mythic-plus/static-data', [
            'expansion_id' => $expansionId,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Raid slugs raider.io currently accepts as `raid=` on /raiding/raid-rankings
     * for an expansion: every /raiding/static-data entry whose `ends` window is
     * still open in at least one region (no window published → assumed open).
     * Combined "tier-…" slugs only exist when raider.io publishes one — Midnight
     * S2 shipped as two individual raids, and the rollover's guessed `tier-mn-2`
     * 400'd the guild seed daily for a week (2026-08-23..28). The rollover
     * validates its --tier-slug against this list.
     *
     * @return list<string>
     */
    public function activeRaidSlugs(int $expansionId): array
    {
        $raids = $this->get('/raiding/static-data', ['expansion_id' => $expansionId])->json('raids') ?? [];

        $active = [];
        foreach ($raids as $raid) {
            $slug = $raid['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $ends = array_values((array) ($raid['ends'] ?? []));
            $open = $ends === [] || array_any($ends, fn ($end) => Carbon::parse((string) $end)->isFuture());
            if ($open) {
                $active[] = $slug;
            }
        }

        return $active;
    }

    /**
     * Dungeon slugs for one season, from /mythic-plus/static-data. Used by the
     * seeder's per-dungeon ladder loop. Empty array when the season is absent.
     *
     * @return list<string>
     */
    public function seasonDungeonSlugs(int $expansionId, string $seasonSlug): array
    {
        foreach ($this->mythicPlusStaticData($expansionId)['seasons'] ?? [] as $season) {
            if (($season['slug'] ?? null) === $seasonSlug) {
                return array_values(array_filter(array_map(
                    fn (array $dungeon): ?string => $dungeon['slug'] ?? null,
                    $season['dungeons'] ?? [],
                )));
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCharacterMythicPlusRuns(string $region, string $realm, string $name): array
    {
        $response = $this->get('/characters/profile', [
            'region' => $region,
            'realm' => $realm,
            'name' => $name,
            'fields' => 'mythic_plus_recent_runs,mythic_plus_best_runs,mythic_plus_highest_level_runs',
        ]);

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRunDetails(string $season, int $keystoneRunId): array
    {
        $response = $this->get('/mythic-plus/run-details', [
            'season' => $season,
            'id' => $keystoneRunId,
        ]);

        return $response->json() ?? [];
    }

    protected function currentRaidSlug(): string
    {
        return Seasons::raiderioTierSlug();
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

    /**
     * Seconds to wait after a 429. header() returns '' (not null) for a missing
     * header, so the old `(int) ($header ?? 60)` collapsed to 0 — an instant
     * re-send that hit a second 429 and threw. Default to 60s and cap at 90s so
     * the wait can't blow past the Horizon job timeout. (P1.9)
     */
    protected function retryAfterSeconds(Response $response): int
    {
        $header = $response->header('Retry-After');

        return min($header !== '' ? (int) $header : 60, 90);
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
                    throw new RaiderIOThrottledException(10, "raiderio: throttle timeout for $path");
                }
            );
    }

    protected function doGet(string $path, array $query): Response
    {
        // Append the optional API key once, here, so callers don't need to know.
        $accessKey = (string) config('raiderio.access_key', '');
        if ($accessKey !== '') {
            $query['access_key'] = $accessKey;
        }

        $attempt5xx = 0;
        $backoffSeconds = [1, 4, 10];

        while (true) {
            try {
                $response = $this->http()->get($path, $query);
            } catch (ConnectionException $e) {
                // A cURL timeout / refused connection is as transient as a 5xx and
                // shares its retry budget. Uncaught, one 15s timeout mid-crawl
                // aborted the whole seed run (observed in prod 2026-07-28).
                if ($attempt5xx < count($backoffSeconds)) {
                    $sleep = $backoffSeconds[$attempt5xx];
                    if ($sleep > 0 && ! $this->skipBackoffSleep()) {
                        sleep($sleep);
                    }
                    $attempt5xx++;

                    continue;
                }

                throw new RaiderIOException(
                    sprintf('raider.io connection failed for %s: %s', $path, $e->getMessage()),
                    0,
                    $e
                );
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();

            if ($status === 429) {
                // Typed + immediate: no in-process sleep. Job middleware
                // (RaiderIORateLimiter) converts this into a non-blocking
                // release() so a throttled crawl worker goes back to the pool.
                throw new RaiderIOThrottledException($this->retryAfterSeconds($response));
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
