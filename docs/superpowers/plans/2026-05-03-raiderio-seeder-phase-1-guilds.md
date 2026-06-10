# Raider.IO Seeder — Phase 1 (Guilds) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a manual artisan command that pulls top mythic raiding guilds per region from raider.io and dispatches the existing Blizzard sync pipeline against them, including a new roster member fan-out so each guild's roster auto-syncs at `Full` depth.

**Architecture:** Lean service under `app/Services/RaiderIO/` (Client + Seeder + DTOs + Exception) plus one artisan command. raider.io is a discovery-only channel — no DTOs leak past the seeder, no raider.io data persisted. Throttle is a Redis token bucket capped at 250/min (17% under raider.io's 300/min public ceiling). Cascades through existing `SyncGuildData` → `SyncGuildRoster` → (new) per-member `SyncCharacterData` Full dispatch. TTL-gated (12h default) on `Character.updated_at` to avoid re-queueing fresh data.

**Tech Stack:** Laravel 13 / PHP 8.4, Horizon-managed Redis queues, PostgreSQL, Pest-style PHPUnit tests, `Http::fake()` for HTTP mocking, `Queue::fake()` for job assertions.

**Phases 2 (Runs) and 3 (Characters) are deliberately out of scope** for this plan and will get their own plans after Phase 1 ships and we have learned from the live cascade. The `seeded_runs` table and the `topRuns` / `topCharactersBySpec` client methods are NOT built in this plan.

---

## File Structure (Phase 1)

**Create:**
- `backend/config/raiderio.php`
- `backend/app/Services/RaiderIO/Exceptions/RaiderIOException.php`
- `backend/app/Services/RaiderIO/DTO/SeedGuildRef.php`
- `backend/app/Services/RaiderIO/DTO/SeedOptions.php`
- `backend/app/Services/RaiderIO/DTO/SeedReport.php`
- `backend/app/Services/RaiderIO/RaiderIOClient.php`
- `backend/app/Services/RaiderIO/RaiderIOSeeder.php`
- `backend/app/Console/Commands/RaiderIOSeed.php`
- `backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`
- `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`
- `backend/tests/Feature/Console/RaiderIOSeedCommandTest.php`
- `backend/tests/fixtures/raiderio/top-guilds-eu.json`
- `backend/tests/fixtures/raiderio/top-guilds-eu-page-2.json`

**Modify:**
- `backend/app/Blizzard/Jobs/SyncGuildRoster.php` — add per-member `SyncCharacterData::Full` dispatch (gated by config flag + TTL)
- `backend/.env.example` — add `RAIDERIO_*` env vars
- `backend/CLAUDE.md` — add "RaiderIO Seeder" section
- `/home/dakiman/projects/guild-service-v2/CLAUDE.md` — one-line pointer

---

## Task 1: Config file

**Files:**
- Create: `backend/config/raiderio.php`

- [ ] **Step 1: Create the config file**

```php
<?php

declare(strict_types=1);

return [
    'base_url' => env('RAIDERIO_BASE_URL', 'https://raider.io/api/v1'),

    'throttle' => [
        'per_minute' => (int) env('RAIDERIO_RATE_PER_MINUTE', 250),
    ],

    'regions' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RAIDERIO_SEED_REGIONS', 'eu,us'))
    ))),

    'season' => env('RAIDERIO_SEED_SEASON', 'season-mn-1'),

    'phase' => [
        'guilds_per_region' => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
    ],

    'character_resync_ttl' => (int) env('RAIDERIO_SEED_CHAR_TTL', 12 * 3600),

    'teammate_crawl_during_seed' => (bool) env('RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED', false),

    'dispatch_chunk_size' => (int) env('RAIDERIO_SEED_CHUNK', 50),

    'dispatch_roster_character_syncs' => (bool) env('RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS', true),
];
```

- [ ] **Step 2: Verify config loads cleanly**

Run: `docker compose exec app php artisan config:show raiderio`
Expected: prints all keys with default values; no errors.

- [ ] **Step 3: Commit**

```bash
git add backend/config/raiderio.php
git commit -m "feat(raiderio): add config file for seeder"
```

---

## Task 2: Exception class

**Files:**
- Create: `backend/app/Services/RaiderIO/Exceptions/RaiderIOException.php`

- [ ] **Step 1: Create the exception**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Exceptions;

use RuntimeException;

class RaiderIOException extends RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/RaiderIO/Exceptions/RaiderIOException.php
git commit -m "feat(raiderio): add RaiderIOException class"
```

---

## Task 3: SeedGuildRef DTO

**Files:**
- Create: `backend/app/Services/RaiderIO/DTO/SeedGuildRef.php`

- [ ] **Step 1: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedGuildRef
{
    public function __construct(
        public string $region,
        public string $realmSlug,
        public string $name,
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/RaiderIO/DTO/SeedGuildRef.php
git commit -m "feat(raiderio): add SeedGuildRef DTO"
```

---

## Task 4: SeedOptions DTO

**Files:**
- Create: `backend/app/Services/RaiderIO/DTO/SeedOptions.php`

- [ ] **Step 1: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedOptions
{
    /**
     * @param  list<string>  $regions
     */
    public function __construct(
        public array $regions,
        public int $limit,
        public bool $force = false,
        public bool $dryRun = false,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            regions: (array) config('raiderio.regions'),
            limit: (int) config('raiderio.phase.guilds_per_region'),
        );
    }

    public function withOverrides(?array $regions = null, ?int $limit = null, ?bool $force = null, ?bool $dryRun = null): self
    {
        return new self(
            regions: $regions ?? $this->regions,
            limit: $limit ?? $this->limit,
            force: $force ?? $this->force,
            dryRun: $dryRun ?? $this->dryRun,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/RaiderIO/DTO/SeedOptions.php
git commit -m "feat(raiderio): add SeedOptions DTO"
```

---

## Task 5: SeedReport DTO

**Files:**
- Create: `backend/app/Services/RaiderIO/DTO/SeedReport.php`

- [ ] **Step 1: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final class SeedReport
{
    public int $considered = 0;
    public int $dispatched = 0;
    public int $skippedTtl = 0;
    public int $skippedDedupe = 0;
    public int $errors = 0;

    public function __construct(
        public readonly string $phase,
        /** @var list<string> */
        public readonly array $regions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'regions' => $this->regions,
            'considered' => $this->considered,
            'dispatched' => $this->dispatched,
            'skipped_ttl' => $this->skippedTtl,
            'skipped_dedupe' => $this->skippedDedupe,
            'errors' => $this->errors,
        ];
    }
}
```

Note: `SeedReport` is mutable on counters by design — it's a simple counting accumulator, not a value object. The phase/regions are readonly.

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/RaiderIO/DTO/SeedReport.php
git commit -m "feat(raiderio): add SeedReport DTO"
```

---

## Task 6: RaiderIOClient happy path (single page)

**Files:**
- Create: `backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`
- Create: `backend/tests/fixtures/raiderio/top-guilds-eu.json`
- Create: `backend/app/Services/RaiderIO/RaiderIOClient.php`

- [ ] **Step 1: Create the fixture**

`backend/tests/fixtures/raiderio/top-guilds-eu.json`:

```json
{
  "raidSlug": "the-voidspire",
  "rankings": {
    "rankedGuilds": [
      {
        "rank": 1,
        "guild": { "name": "Echo", "realm": { "name": "Tarren Mill", "slug": "tarren-mill" }, "region": { "slug": "eu" } }
      },
      {
        "rank": 2,
        "guild": { "name": "Method", "realm": { "name": "Twisting Nether", "slug": "twisting-nether" }, "region": { "slug": "eu" } }
      },
      {
        "rank": 3,
        "guild": { "name": "Pieces", "realm": { "name": "Stormscale", "slug": "stormscale" }, "region": { "slug": "eu" } }
      }
    ]
  }
}
```

- [ ] **Step 2: Write the failing test**

`backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientTest extends TestCase
{
    public function test_top_guilds_yields_guild_refs_from_response(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);
        Http::fake([
            'raider.io/api/v1/guilds/static-raid-rankings*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);

        $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

        $this->assertCount(3, $refs);
        $this->assertInstanceOf(SeedGuildRef::class, $refs[0]);
        $this->assertSame('eu', $refs[0]->region);
        $this->assertSame('tarren-mill', $refs[0]->realmSlug);
        $this->assertSame('Echo', $refs[0]->name);
        $this->assertSame('Method', $refs[1]->name);
        $this->assertSame('Pieces', $refs[2]->name);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php --filter test_top_guilds_yields_guild_refs_from_response`
Expected: FAIL — `Class "App\Services\RaiderIO\RaiderIOClient" not found`.

- [ ] **Step 4: Implement minimal RaiderIOClient**

`backend/app/Services/RaiderIO/RaiderIOClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class RaiderIOClient
{
    /**
     * Yields up to $limit SeedGuildRef rows for a region.
     * Pulls /guilds/static-raid-rankings page-by-page (20 rows/page).
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
            $response = $this->http()->get("/guilds/static-raid-rankings", [
                'raid' => $raid,
                'difficulty' => 'mythic',
                'region' => $region,
                'limit' => $perPage,
                'page' => $page,
            ]);

            $this->ensureOk($response);
            $rows = $response->json('rankings.rankedGuilds') ?? [];

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
        // Phase 1: hardcode current Midnight raid that drives mythic rankings.
        // Future: drive from a config key once raid rotation matters.
        return 'the-voidspire';
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('raiderio.base_url'))
            ->acceptJson()
            ->timeout(15);
    }

    protected function ensureOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RaiderIOException(
            sprintf('raider.io returned HTTP %d for %s', $response->status(), $response->effectiveUri()?->__toString() ?? '?')
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php --filter test_top_guilds_yields_guild_refs_from_response`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/RaiderIO/RaiderIOClient.php backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php backend/tests/fixtures/raiderio/top-guilds-eu.json
git commit -m "feat(raiderio): add RaiderIOClient::topGuilds"
```

---

## Task 7: RaiderIOClient pagination

**Files:**
- Create: `backend/tests/fixtures/raiderio/top-guilds-eu-page-2.json`
- Modify: `backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`

- [ ] **Step 1: Create page-2 fixture (20 minimal rows)**

`backend/tests/fixtures/raiderio/top-guilds-eu-page-2.json`:

```json
{
  "rankings": {
    "rankedGuilds": [
      { "rank": 21, "guild": { "name": "G21", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
      { "rank": 22, "guild": { "name": "G22", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
      { "rank": 23, "guild": { "name": "G23", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
      { "rank": 24, "guild": { "name": "G24", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
      { "rank": 25, "guild": { "name": "G25", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } }
    ]
  }
}
```

(Five rows is enough — we'll request limit=23, pulling 20 from page 0 and 3 from page 1.)

For Step 1 we also need a 20-row page-1 fixture. Generate it with this one-liner:

```bash
cd backend
php -r '
$rows = [];
for ($i = 1; $i <= 20; $i++) {
    $rows[] = ["rank" => $i, "guild" => ["name" => "G$i", "realm" => ["slug" => "draenor"], "region" => ["slug" => "eu"]]];
}
echo json_encode(["rankings" => ["rankedGuilds" => $rows]], JSON_PRETTY_PRINT);
' > tests/fixtures/raiderio/top-guilds-eu-page-1-full.json
```

This produces 20 unique guild rows `G1`..`G20`, all on `draenor`, region `eu`. Verify the file has 20 rows: `jq '.rankings.rankedGuilds | length' tests/fixtures/raiderio/top-guilds-eu-page-1-full.json` → `20`.

- [ ] **Step 2: Write the pagination failing test**

Append to `backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`:

```php
public function test_top_guilds_paginates_when_limit_exceeds_page_size(): void
{
    $page0 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu-page-1-full.json')), true);
    $page1 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu-page-2.json')), true);

    Http::fakeSequence('raider.io/api/v1/guilds/static-raid-rankings*')
        ->push($page0, 200)
        ->push($page1, 200);

    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    $refs = iterator_to_array($client->topGuilds('eu', 23), preserve_keys: false);

    $this->assertCount(23, $refs);
    Http::assertSentCount(2);
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php --filter test_top_guilds_paginates`
Expected: PASS (no implementation change — pagination loop is already in place).

If it fails because `Http::fakeSequence` doesn't accept a URL pattern in your Laravel version, swap to:

```php
Http::fake(function ($request) use ($page0, $page1) {
    $page = (int) ($request->data()['page'] ?? 0);
    return Http::response($page === 0 ? $page0 : $page1, 200);
});
```

- [ ] **Step 4: Commit**

```bash
git add backend/tests/fixtures/raiderio/top-guilds-eu-page-1-full.json backend/tests/fixtures/raiderio/top-guilds-eu-page-2.json backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php
git commit -m "test(raiderio): cover RaiderIOClient pagination"
```

---

## Task 8: RaiderIOClient 429 backoff with single retry

**Files:**
- Modify: `backend/app/Services/RaiderIO/RaiderIOClient.php`
- Modify: `backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`

- [ ] **Step 1: Write failing 429-then-200 test**

Append to `RaiderIOClientTest.php`:

```php
public function test_top_guilds_retries_once_after_429_with_retry_after(): void
{
    $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);

    $calls = 0;
    Http::fake(function ($request) use ($fixture, &$calls) {
        $calls++;
        if ($calls === 1) {
            return Http::response('', 429, ['Retry-After' => '0']);
        }
        return Http::response($fixture, 200);
    });

    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

    $this->assertCount(3, $refs);
    $this->assertSame(2, $calls);
}

public function test_top_guilds_throws_after_second_429(): void
{
    Http::fake(fn () => Http::response('', 429, ['Retry-After' => '0']));

    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    $this->expectException(\App\Services\RaiderIO\Exceptions\RaiderIOException::class);
    iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php --filter test_top_guilds_retries_once_after_429`
Expected: FAIL (`Retry-After` not honored — first 429 throws immediately).

- [ ] **Step 3: Modify `ensureOk` and `http()` to handle 429**

In `RaiderIOClient.php`, replace `ensureOk()` and add a new request wrapper:

```php
protected function get(string $path, array $query): Response
{
    $attempt = 0;
    while (true) {
        $response = $this->http()->get($path, $query);

        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 429 && $attempt < 1) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 60);
            // Test fixtures pass Retry-After: 0, so we still call sleep — kept short by tests
            if ($retryAfter > 0) {
                sleep($retryAfter);
            }
            $attempt++;
            continue;
        }

        throw new RaiderIOException(
            sprintf('raider.io returned HTTP %d for %s', $response->status(), $path)
        );
    }
}
```

Then replace the `$this->http()->get(...)` call inside `topGuilds()` with `$this->get('/guilds/static-raid-rankings', [...])` and remove the now-unused `ensureOk()` method.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`
Expected: all four tests PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/RaiderIO/RaiderIOClient.php backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php
git commit -m "feat(raiderio): retry once on HTTP 429 with Retry-After"
```

---

## Task 9: RaiderIOClient 5xx exponential backoff

**Files:**
- Modify: `backend/app/Services/RaiderIO/RaiderIOClient.php`
- Modify: `backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`

- [ ] **Step 1: Write failing 502-recovery test**

Append:

```php
public function test_top_guilds_retries_on_5xx_up_to_three_times(): void
{
    $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);

    $calls = 0;
    Http::fake(function () use ($fixture, &$calls) {
        $calls++;
        return $calls < 3
            ? Http::response('', 502)
            : Http::response($fixture, 200);
    });

    // Disable real backoff sleeps for this unit test.
    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    $refs = iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);

    $this->assertCount(3, $refs);
    $this->assertSame(3, $calls);
}

public function test_top_guilds_throws_after_3_5xx_failures(): void
{
    Http::fake(fn () => Http::response('', 502));

    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    $this->expectException(\App\Services\RaiderIO\Exceptions\RaiderIOException::class);
    iterator_to_array($client->topGuilds('eu', 3), preserve_keys: false);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php --filter test_top_guilds_retries_on_5xx`
Expected: FAIL.

- [ ] **Step 3: Update `get()` to retry 5xx with backoff**

```php
protected function get(string $path, array $query): Response
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
            if ($retryAfter > 0) {
                sleep($retryAfter);
            }
            $attempt429++;
            continue;
        }

        if ($status >= 500 && $attempt5xx < count($backoffSeconds)) {
            $sleep = $backoffSeconds[$attempt5xx];
            if ($sleep > 0 && ! app()->runningUnitTests()) {
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
```

The `app()->runningUnitTests()` check skips the real backoff sleep during PHPUnit so tests stay fast. (This helper returns `true` when `APP_ENV=testing`.)

- [ ] **Step 4: Run all client tests**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`
Expected: all six tests PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/RaiderIO/RaiderIOClient.php backend/tests/Unit/Services/RaiderIO/RaiderIOClientTest.php
git commit -m "feat(raiderio): retry on 5xx with exponential backoff"
```

---

## Task 10: RaiderIOClient throttle (Redis token bucket)

**Files:**
- Modify: `backend/app/Services/RaiderIO/RaiderIOClient.php`

This task adds the 250/min throttle via `Redis::throttle`. Skipping a dedicated unit test here — `Redis::throttle` is Laravel framework code; we do not re-test the framework. The throttle is verified end-to-end in the feature test (Task 13).

- [ ] **Step 1: Wrap `get()` in a Redis throttle**

In `RaiderIOClient.php`, modify `get()` to acquire a token before each HTTP call:

```php
use Illuminate\Support\Facades\Redis;

// ...

protected function get(string $path, array $query): Response
{
    return Redis::throttle('raiderio:requests')
        ->allow((int) config('raiderio.throttle.per_minute'))
        ->every(60)
        ->block(30)
        ->then(function () use ($path, $query) {
            return $this->doGet($path, $query);
        }, function () use ($path) {
            throw new RaiderIOException("raiderio: throttle timeout for $path");
        });
}

/**
 * Inner request loop with retry handling — separated from throttle wrapper for clarity.
 */
protected function doGet(string $path, array $query): Response
{
    $attempt429 = 0;
    $attempt5xx = 0;
    $backoffSeconds = [1, 4, 10];

    while (true) {
        $response = $this->http()->get($path, $query);
        // ...same retry logic as Task 9...
    }
}
```

(Move the retry-loop body from Task 9's `get()` into the new `doGet()`. The outer `get()` becomes the throttle wrapper.)

- [ ] **Step 2: Run all client tests**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientTest.php`
Expected: all PASS. The unit tests use `Cache::array` driver per `phpunit.xml`, so `Redis::throttle` falls back gracefully OR — if it fails because Redis is not available in unit test mode — see Step 3.

- [ ] **Step 3 (only if Step 2 fails): Make throttle skippable in test env**

If unit tests fail because Redis is unavailable, wrap the throttle with an env check:

```php
protected function get(string $path, array $query): Response
{
    if (app()->runningUnitTests()) {
        return $this->doGet($path, $query);
    }
    return Redis::throttle(...)->then(...);
}
```

- [ ] **Step 4: Commit**

```bash
git add backend/app/Services/RaiderIO/RaiderIOClient.php
git commit -m "feat(raiderio): add 250/min Redis throttle to RaiderIOClient"
```

---

## Task 11: RaiderIOSeeder::seedGuilds — happy path

**Files:**
- Create: `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`
- Create: `backend/app/Services/RaiderIO/RaiderIOSeeder.php`

- [ ] **Step 1: Write the failing happy-path test**

`backend/tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Blizzard\Jobs\SyncGuildData;
use App\Services\RaiderIO\DTO\SeedGuildRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RaiderIOSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_seed_guilds_dispatches_one_sync_per_ref(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topGuilds')->with('eu', 3)->andReturn((function () {
            yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
            yield new SeedGuildRef('eu', 'twisting-nether', 'Method');
            yield new SeedGuildRef('eu', 'stormscale', 'Pieces');
        })());

        $seeder = app(RaiderIOSeeder::class);
        $opts = new SeedOptions(regions: ['eu'], limit: 3);

        $report = $seeder->seedGuilds($opts);

        Queue::assertPushed(SyncGuildData::class, 3);
        $this->assertSame(3, $report->considered);
        $this->assertSame(3, $report->dispatched);
        $this->assertSame(0, $report->skippedTtl);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`
Expected: FAIL — `Class "App\Services\RaiderIO\RaiderIOSeeder" not found`.

- [ ] **Step 3: Implement minimal RaiderIOSeeder**

`backend/app/Services/RaiderIO/RaiderIOSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO;

use App\Blizzard\Jobs\SyncGuildData;
use App\Models\Guild;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedReport;
use App\Services\RaiderIO\Exceptions\RaiderIOException;
use Illuminate\Support\Facades\Log;

class RaiderIOSeeder
{
    public function __construct(
        protected RaiderIOClient $client,
    ) {}

    public function seedGuilds(SeedOptions $opts): SeedReport
    {
        $report = new SeedReport(phase: 'guilds', regions: $opts->regions);

        Log::info('raiderio.seed.start', ['phase' => 'guilds', 'regions' => $opts->regions, 'limit' => $opts->limit]);

        foreach ($opts->regions as $region) {
            try {
                foreach ($this->client->topGuilds($region, $opts->limit) as $ref) {
                    $report->considered++;

                    if (! $opts->force && $this->guildIsFresh($ref)) {
                        $report->skippedTtl++;
                        continue;
                    }

                    if ($opts->dryRun) {
                        $report->dispatched++;
                        continue;
                    }

                    SyncGuildData::dispatch($ref->region, $ref->realmSlug, $ref->name);
                    $report->dispatched++;
                }
            } catch (RaiderIOException $e) {
                $report->errors++;
                Log::warning('raiderio.seed.error', [
                    'phase' => 'guilds',
                    'region' => $region,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('raiderio.seed.complete', $report->toArray());

        return $report;
    }

    protected function guildIsFresh(\App\Services\RaiderIO\DTO\SeedGuildRef $ref): bool
    {
        $existing = Guild::byIdentity($ref->name, $ref->realmSlug, $ref->region)->first();
        return $existing !== null && ! $existing->isRosterStale();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/RaiderIO/RaiderIOSeeder.php backend/tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php
git commit -m "feat(raiderio): add RaiderIOSeeder::seedGuilds happy path"
```

---

## Task 12: RaiderIOSeeder — TTL skip, force bypass, dry-run, per-region error isolation

**Files:**
- Modify: `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`

- [ ] **Step 1: Write the TTL-skip test**

Append:

```php
public function test_seed_guilds_skips_fresh_guilds(): void
{
    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topGuilds')->andReturn((function () {
        yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
    })());

    // Insert a fresh guild whose roster_synced_at is recent
    \App\Models\Guild::factory()->create([
        'region' => 'eu',
        'realm' => 'tarren-mill',
        'name' => 'Echo',
        'roster_synced_at' => now()->subMinutes(5),  // well within isRosterStale threshold
    ]);

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu'], limit: 1));

    Queue::assertNothingPushed();
    $this->assertSame(1, $report->considered);
    $this->assertSame(0, $report->dispatched);
    $this->assertSame(1, $report->skippedTtl);
}

public function test_seed_guilds_force_bypasses_ttl_skip(): void
{
    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topGuilds')->andReturn((function () {
        yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
    })());

    \App\Models\Guild::factory()->create([
        'region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo',
        'roster_synced_at' => now()->subMinutes(5),
    ]);

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu'], limit: 1, force: true));

    Queue::assertPushed(\App\Blizzard\Jobs\SyncGuildData::class, 1);
    $this->assertSame(1, $report->dispatched);
}

public function test_seed_guilds_dry_run_dispatches_nothing(): void
{
    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topGuilds')->andReturn((function () {
        yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
        yield new SeedGuildRef('eu', 'twisting-nether', 'Method');
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu'], limit: 2, dryRun: true));

    Queue::assertNothingPushed();
    $this->assertSame(2, $report->considered);
    $this->assertSame(2, $report->dispatched);  // dry-run still increments dispatched counter
}

public function test_seed_guilds_isolates_per_region_errors(): void
{
    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topGuilds')->with('eu', 1)->andReturn((function () {
        yield new SeedGuildRef('eu', 'tarren-mill', 'Echo');
    })());
    $client->shouldReceive('topGuilds')->with('us', 1)->andThrow(
        new \App\Services\RaiderIO\Exceptions\RaiderIOException('boom')
    );

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedGuilds(new SeedOptions(regions: ['eu', 'us'], limit: 1));

    Queue::assertPushed(\App\Blizzard\Jobs\SyncGuildData::class, 1);
    $this->assertSame(1, $report->dispatched);
    $this->assertSame(1, $report->errors);
}
```

- [ ] **Step 2: Run all seeder tests**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php`
Expected: all four tests PASS (no implementation change needed — Task 11's seeder already handles all these paths).

If `Guild::factory()` doesn't exist, check `database/factories/` for an existing `GuildFactory`. If absent, create a minimal one:

```php
// database/factories/GuildFactory.php
<?php

namespace Database\Factories;

use App\Models\Guild;
use Illuminate\Database\Eloquent\Factories\Factory;

class GuildFactory extends Factory
{
    protected $model = Guild::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'faction' => 'horde',
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php
# Add factory file if you created one
git add backend/database/factories/GuildFactory.php 2>/dev/null
git commit -m "test(raiderio): cover seeder TTL skip, force, dry-run, per-region errors"
```

---

## Task 13: Modify SyncGuildRoster to dispatch per-member SyncCharacterData

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncGuildRoster.php`
- Create: `backend/tests/Feature/Blizzard/Jobs/SyncGuildRosterCharacterFanoutTest.php`

This is the most behavior-changing task in the plan. `SyncGuildRoster` currently dispatches `Bus::batch` of `SyncCharacterData` at `Shallow` depth. We're adding a parallel dispatch of `Full` syncs gated by:
1. `config('raiderio.dispatch_roster_character_syncs')` (default `true`)
2. `Character.updated_at` TTL gate

The existing `Shallow` Bus::batch is preserved — it serves a different purpose (lightweight roster member basic-profile fetch). The new Full dispatches add cascade depth on top.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncGuildRosterCharacterFanoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Bus::fake();
    }

    public function test_dispatches_full_sync_for_each_member_when_flag_enabled(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', true);
        config()->set('raiderio.character_resync_ttl', 3600);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 80,
        ]);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Beta', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        (new SyncGuildRoster($guild))->handle();

        Queue::assertPushed(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full && $job->name === 'Alpha';
        });
        Queue::assertPushed(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full && $job->name === 'Beta';
        });
    }

    public function test_skips_full_dispatch_when_member_was_recently_updated(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', true);
        config()->set('raiderio.character_resync_ttl', 3600);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Fresh', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        // Existing Character row, recently synced.
        Character::factory()->create([
            'name' => 'Fresh',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'updated_at' => now()->subMinutes(5),
        ]);

        (new SyncGuildRoster($guild))->handle();

        Queue::assertNotPushed(SyncCharacterData::class, fn ($job) =>
            $job->depth === SyncDepth::Full && $job->name === 'Fresh'
        );
    }

    public function test_skips_all_full_dispatches_when_flag_disabled(): void
    {
        config()->set('raiderio.dispatch_roster_character_syncs', false);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'Echo']);
        GuildMember::create([
            'guild_id' => $guild->id, 'name' => 'Alpha', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        (new SyncGuildRoster($guild))->handle();

        Queue::assertNotPushed(SyncCharacterData::class, fn ($job) => $job->depth === SyncDepth::Full);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildRosterCharacterFanoutTest.php`
Expected: all three FAIL — current `SyncGuildRoster` does not dispatch Full syncs.

- [ ] **Step 3: Modify `SyncGuildRoster::handle()`**

Add the per-member Full-fanout block after the existing `Bus::batch(...)->dispatch()` call:

```php
public function handle(): void
{
    $minLevel = (int) config('blizzard.min_level_for_character_lookup', 70);

    $members = $this->guild->members()
        ->where('level', '>=', $minLevel)
        ->get();

    $shallowJobs = $members
        ->map(fn ($member) => new SyncCharacterData(
            region: $this->guild->region,
            realm: $member->realm,
            name: $member->name,
            depth: SyncDepth::Shallow,
        ))
        ->all();

    if (! empty($shallowJobs)) {
        Bus::batch($shallowJobs)
            ->allowFailures()
            ->name("guild-roster-sync:{$this->guild->id}")
            ->onQueue('blizzard-roster-sync')
            ->dispatch();
    }

    if (config('raiderio.dispatch_roster_character_syncs', false)) {
        $this->dispatchFullSyncsForMembers($members);
    }
}

protected function dispatchFullSyncsForMembers(\Illuminate\Support\Collection $members): void
{
    $ttl = (int) config('raiderio.character_resync_ttl', 12 * 3600);
    $cutoff = now()->subSeconds($ttl);

    foreach ($members as $member) {
        $existing = \App\Models\Character::byIdentity($member->name, $member->realm, $this->guild->region)->first();
        if ($existing !== null && $existing->updated_at !== null && $existing->updated_at->isAfter($cutoff)) {
            continue;
        }

        SyncCharacterData::dispatch(
            region: $this->guild->region,
            realm: $member->realm,
            name: $member->name,
            depth: SyncDepth::Full,
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildRosterCharacterFanoutTest.php`
Expected: all three PASS.

- [ ] **Step 5: Run the existing SyncGuildRoster test suite to make sure we didn't regress**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/`
Expected: all PASS (existing tests untouched by the additive change).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Blizzard/Jobs/SyncGuildRoster.php backend/tests/Feature/Blizzard/Jobs/SyncGuildRosterCharacterFanoutTest.php
git commit -m "feat(blizzard): dispatch Full SyncCharacterData per roster member, TTL-gated"
```

---

## Task 14: Artisan command — `raiderio:seed`

**Files:**
- Create: `backend/app/Console/Commands/RaiderIOSeed.php`
- Create: `backend/tests/Feature/Console/RaiderIOSeedCommandTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncGuildData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RaiderIOSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-guilds-eu.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);
    }

    public function test_phase_guilds_dispatches_sync_guild_data_per_ref(): void
    {
        $exit = $this->artisan('raiderio:seed', [
            '--phase' => 'guilds',
            '--limit' => 3,
            '--regions' => 'eu',
        ])->assertSuccessful()->run();

        Queue::assertPushed(SyncGuildData::class, 3);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'guilds',
            '--limit' => 3,
            '--regions' => 'eu',
            '--dry-run' => true,
        ])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_invalid_phase_returns_failure_exit(): void
    {
        $this->artisan('raiderio:seed', ['--phase' => 'bogus'])
            ->assertFailed();
    }

    public function test_unsupported_phase_runs_returns_not_implemented(): void
    {
        $this->artisan('raiderio:seed', ['--phase' => 'runs'])
            ->expectsOutputToContain('not yet implemented')
            ->assertFailed();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedCommandTest.php`
Expected: FAIL — `Command "raiderio:seed" is not defined`.

- [ ] **Step 3: Implement the command**

`backend/app/Console/Commands/RaiderIOSeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Console\Command;

class RaiderIOSeed extends Command
{
    protected $signature = 'raiderio:seed
        {--phase= : guilds|runs|characters|all (only "guilds" implemented in phase 1)}
        {--limit= : Override per-phase limit (e.g., guilds_per_region)}
        {--regions= : Comma-separated region slugs (overrides config)}
        {--force : Bypass TTL gates}
        {--dry-run : Skip dispatches; report what would happen}';

    protected $description = 'Bootstrap the database from raider.io top-lists.';

    public function handle(RaiderIOSeeder $seeder): int
    {
        $phase = (string) $this->option('phase');
        $allowed = ['guilds', 'runs', 'characters', 'all'];

        if (! in_array($phase, $allowed, true)) {
            $this->error("Invalid --phase. Allowed: " . implode(', ', $allowed));
            return self::FAILURE;
        }

        if ($phase !== 'guilds') {
            $this->error("Phase '$phase' not yet implemented (phase 1 ships guilds only).");
            return self::FAILURE;
        }

        $opts = $this->buildOptions();
        $report = $seeder->seedGuilds($opts);

        $this->table(
            ['phase', 'regions', 'considered', 'dispatched', 'skipped_ttl', 'errors'],
            [[
                $report->phase,
                implode(',', $report->regions),
                $report->considered,
                $report->dispatched,
                $report->skippedTtl,
                $report->errors,
            ]]
        );

        return self::SUCCESS;
    }

    protected function buildOptions(): SeedOptions
    {
        $regions = $this->option('regions')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('regions')))))
            : (array) config('raiderio.regions');

        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('raiderio.phase.guilds_per_region');

        return new SeedOptions(
            regions: $regions,
            limit: $limit,
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
        );
    }
}
```

Laravel auto-discovers commands under `app/Console/Commands/` via `Kernel::$load`, so no kernel edit needed (verify by checking `app/Console/Kernel.php` — should call `$this->load(__DIR__.'/Commands')`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedCommandTest.php`
Expected: all four PASS.

- [ ] **Step 5: Verify command is registered**

Run: `docker compose exec app php artisan list | grep raiderio`
Expected: `raiderio:seed   Bootstrap the database from raider.io top-lists.`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands/RaiderIOSeed.php backend/tests/Feature/Console/RaiderIOSeedCommandTest.php
git commit -m "feat(raiderio): add raiderio:seed artisan command"
```

---

## Task 15: Update `.env.example`

**Files:**
- Modify: `backend/.env.example`

- [ ] **Step 1: Append the env var stubs**

Add this block to `backend/.env.example` (place after the `BLIZZARD_*` block; exact location depends on file layout):

```ini
# raider.io seeder (phase 1: guilds)
RAIDERIO_BASE_URL=https://raider.io/api/v1
RAIDERIO_RATE_PER_MINUTE=250
RAIDERIO_SEED_REGIONS=eu,us
RAIDERIO_SEED_SEASON=season-mn-1
RAIDERIO_SEED_GUILDS_PER_REGION=10
RAIDERIO_SEED_CHAR_TTL=43200
RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED=false
RAIDERIO_SEED_CHUNK=50
RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS=true
```

- [ ] **Step 2: Commit**

```bash
git add backend/.env.example
git commit -m "docs(raiderio): document RAIDERIO_* env vars in .env.example"
```

---

## Task 16: Update `backend/CLAUDE.md`

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Append the new section**

Insert after the existing "Blizzard Module" section, before "Sync Depth". Use the exact content from the spec's "Documentation deliverables" section §1, with one adjustment for the phase-1 reality (only Guilds is shipped):

```markdown
### RaiderIO Seeder (`app/Services/RaiderIO/`)

Lean discovery layer for bootstrapping the database from raider.io top-lists.
Not a full module — raider.io is a throwaway discovery channel only.
DTOs do not leak past `RaiderIOSeeder`; nothing raider.io-shaped is persisted.

- **Architecture.** Single `RaiderIOClient` (Guzzle + Redis token-bucket throttle
  at 250/min, 17% under the 300/min public ceiling), one `RaiderIOSeeder`
  orchestrator (currently exposes `seedGuilds`; `seedRuns` and `seedCharacters`
  are planned for phases 2-3), one artisan command (`raiderio:seed`). Reuses
  existing `SyncGuildData` / `SyncCharacterData` jobs end-to-end — seeder
  dispatches and forgets.
- **Trigger.** Manual artisan only. No scheduler entry today; add
  `Schedule::command('raiderio:seed --phase=guilds')->weekly()` later if desired.
  Run `--dry-run` first when memory is a concern (home-server).
- **Phase 1 (shipped): Guilds.** `php artisan raiderio:seed --phase=guilds
  --limit=N --regions=eu,us` pulls top mythic raiding guilds, dispatches
  `SyncGuildData` per guild. Roster fan-out modification on
  `SyncGuildRoster::handle()` then dispatches `SyncCharacterData::Full` for
  each roster member (TTL-gated on `Character.updated_at`).
- **Phases 2-3 (not shipped): Runs, Characters.** Plans:
  `docs/superpowers/plans/2026-05-03-raiderio-seeder-phase-2-runs.md` (TBD)
  and `...-phase-3-characters.md` (TBD). The `seeded_runs` table and the
  `topRuns` / `topCharactersBySpec` client methods do not exist yet.
- **Dedupe model (phase 1).** Guilds: existing `Guild::isRosterStale()` is
  reused (config/blizzard.php threshold). `--force` flag bypasses for the
  future "manual re-sync" UI button.
- **Roster fan-out.** `SyncGuildRoster` previously created `guild_members`
  shells without dispatching character syncs. Phase 1 adds opt-in fan-out
  gated by `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS` (default true). TTL gate
  reads `Character.updated_at` against `RAIDERIO_SEED_CHAR_TTL` (default 12h).
  The pre-existing `SyncDepth::Shallow` `Bus::batch` dispatch is unchanged
  — the new Full dispatches are additive.
- **Teammate crawl during seed.** Independent of the global
  `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` flag. Seeder honors
  `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` (default false) — flip on for a
  deliberate "blow it out" run; expect a 25-guild seed to balloon to
  ~1,500+ characters via depth-2 crawl. (Wiring this into the seed loop
  is part of Phase 2 — phase 1 just defines the env var.)
- **Rate limits.** raider.io 300/min public ceiling (no API-key tier known
  as of 2026-05-03). Client-side throttle 250/min. Blizzard-side: existing
  `BlizzardRateLimiter` paces cascaded jobs at 80/s, 30k/hr — ~1,500 Full
  character syncs/hour ceiling. Seeder may dispatch tens of thousands of
  jobs in seconds; queue drains over hours/days.
- **Memory profile.** Generator-based pagination, chunked dispatch
  (`RAIDERIO_SEED_CHUNK`, default 50). Resident memory ~10-15 MB during a
  phase. Redis queue depth scales with dispatched job count — Horizon's
  problem, not the seeder's.
- **Future requirement (not phase 1).** If raider.io usage expands beyond
  discovery (e.g. consuming raider.io's score breakdowns, guild attendance,
  alt-tracking), promote `app/Services/RaiderIO/` to a full
  `app/RaiderIO/` module mirroring `app/Blizzard/` (Client, DTO, Mapper,
  Jobs, Middleware, ServiceProvider). Today's lean shape is right *only*
  because raider.io is discovery-only.

#### Env vars

`RAIDERIO_BASE_URL`, `RAIDERIO_RATE_PER_MINUTE` (default 250),
`RAIDERIO_SEED_REGIONS` (default `eu,us`),
`RAIDERIO_SEED_SEASON` (default `season-mn-1` — bump per expansion/season),
`RAIDERIO_SEED_GUILDS_PER_REGION` (default 10),
`RAIDERIO_SEED_CHAR_TTL` (default 43200 = 12h),
`RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` (default false; not yet wired in phase 1),
`RAIDERIO_SEED_CHUNK` (default 50),
`RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS` (default true).

#### Common invocations

\```bash
# First run — verify on home-server with tiny budget
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu --dry-run
php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu

# Default: 10 guilds × eu,us
php artisan raiderio:seed --phase=guilds

# Force-resync a region (future "manual re-sync" UI hook)
php artisan raiderio:seed --phase=guilds --regions=eu --force
\```
```

(Replace `\``` ` triple-backticks with real triple-backticks when pasting.)

- [ ] **Step 2: Commit**

```bash
git add backend/CLAUDE.md
git commit -m "docs(raiderio): add RaiderIO Seeder section to backend/CLAUDE.md"
```

---

## Task 17: Update root cross-repo `CLAUDE.md`

**Files:**
- Modify: `/home/dakiman/projects/guild-service-v2/CLAUDE.md`

- [ ] **Step 1: Add the one-line pointer**

Append to the "Project context" bullet list:

```markdown
- **RaiderIO seeder.** `php artisan raiderio:seed` (in `backend/`) bootstraps
  the database from raider.io top-lists. Phase 1 ships guilds only; phases
  2-3 (runs, characters) planned. Discovery-only — see `backend/CLAUDE.md`
  "RaiderIO Seeder" section. Default regions `eu,us`, season `season-mn-1`.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(raiderio): add cross-repo pointer for raider.io seeder"
```

---

## Task 18: Full test suite + style check

- [ ] **Step 1: Run the entire test suite**

Run: `docker compose exec app composer test`
Expected: all PASS, no regressions.

- [ ] **Step 2: Run Laravel Pint**

Run: `docker compose exec app ./vendor/bin/pint`
Expected: clean output, or auto-fixes applied to new files.

- [ ] **Step 3: If Pint applied fixes, commit them**

```bash
git status
# If files changed:
git add -u
git commit -m "style: apply Pint fixes to new RaiderIO files"
```

- [ ] **Step 4: Restart Horizon container**

Per `backend/CLAUDE.md`: "docker compose restart horizon required after job/mapper/client edits."

Run: `docker compose restart horizon`
Expected: container restarts, no errors in `docker compose logs horizon | tail -20`.

---

## Task 19: Manual smoke test on home-server

**Goal:** verify memory profile and end-to-end cascade behavior with the smallest possible real run.

- [ ] **Step 1: Dry-run against EU only, limit 10**

Run: `docker compose exec app php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu --dry-run`
Expected: command prints SeedReport table with `considered=10`, `dispatched=10`, `skipped_ttl=0` (assuming empty DB), no jobs in queue afterwards.

Verify: `docker compose exec app php artisan queue:size` (or check Horizon dashboard) → 0 jobs queued.

- [ ] **Step 2: Real run, EU only, limit 10**

Make sure Horizon is running before this step.

```bash
docker compose ps horizon  # should be Up
docker stats app horizon --no-stream  # baseline memory
```

Run: `docker compose exec app php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu`
Expected: SeedReport prints `dispatched=10`. Horizon dashboard shows `blizzard-roster-sync` queue depth ramps.

- [ ] **Step 3: Watch memory during cascade**

```bash
docker stats --no-stream
# Observe: app container memory should not climb past ~150 MB; horizon ~200-400 MB.
```

If memory climbs unbounded, **stop here** and investigate before scaling up.

- [ ] **Step 4: Wait ~1 hour, check DB**

```bash
docker compose exec app php artisan tinker
>>> App\Models\Guild::count()
>>> App\Models\Character::count()
>>> App\Models\Character::whereNotNull('updated_at')->count()
```

Expected: ~10 guilds, ~50-300 characters (depending on guild sizes), majority with `updated_at` set.

- [ ] **Step 5: Re-run the same command — verify dedupe**

Run: `docker compose exec app php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu`
Expected: SeedReport shows `skipped_ttl=10` (or close — guilds whose `roster_synced_at` is within `isRosterStale` threshold get skipped). `dispatched` near 0.

- [ ] **Step 6: Test `--force` bypass**

Run: `docker compose exec app php artisan raiderio:seed --phase=guilds --limit=10 --regions=eu --force`
Expected: `dispatched=10` again, skip counters at 0.

If all steps pass, Phase 1 is done.

---

## Phase 2 / Phase 3 — placeholder

Phases 2 (Runs) and 3 (Characters) are intentionally not in this plan. They will get their own plans after Phase 1 is shipped, manually verified on the home-server, and we have a feel for actual cascade behavior, queue depth, and rate-limit headroom under live load. The spec at `docs/superpowers/specs/2026-05-03-raiderio-seeder-design.md` already covers the design for those phases — the plans will be a writing-plans pass against the relevant spec sections.

What phase 1 leaves in place that phases 2/3 will need:
- `RaiderIOClient` is structured to add `topRuns()` and `topCharactersBySpec()` as additional methods alongside `topGuilds()`.
- `RaiderIOSeeder` is structured to add `seedRuns()` and `seedCharacters()` alongside `seedGuilds()`.
- `RaiderIOSeed` command's `--phase=runs` and `--phase=characters` already validate but error with "not yet implemented" — phase 2/3 plans will swap that out for real handlers.
- The `SeededRun` model and `seeded_runs` table do NOT exist yet — phase 2 creates them.
- The `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` env var is documented but not wired into the seed loop yet — phase 2 wires it.
