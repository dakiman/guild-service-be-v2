# Raider.IO Seeder — Phase 2 (Runs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `--phase=runs` to the existing `raiderio:seed` command — pull top mythic+ runs per region from raider.io, dedupe via a new `seeded_runs` ledger, dispatch `SyncCharacterData::Full` for each run's 5-member party, and (optionally) propagate the seed via teammate-crawl override.

**Architecture:** Reuses Phase 1's `RaiderIOClient` and `RaiderIOSeeder` services as-is; adds two new DTOs (`SeedRunRef`, `SeedCharacterRef`), a tiny `seeded_runs` table, and one new method on each of the client + seeder. raider.io endpoint `/mythic-plus/runs?season={s}&region={r}&page={N}` returns 20 runs per page; each run carries `keystone_run_id` (bigint, used directly as dedupe key) and a `roster[]` of 5 character refs. No raider.io data is persisted beyond the dedupe ledger — the cascade fills `dungeon_runs` etc. via the regular `SyncCharacterData::Full` mythic+ slice. Phase 2 also wires the previously-unused `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` flag via a new `forceTeammateCrawl` constructor param on `SyncCharacterData`.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL, Horizon-managed Redis queues, Pest-style PHPUnit, `Http::fake()` + `Bus::fake()` for tests.

**Out of scope (deferred to Phase 3):**
- `--phase=characters` handler (Phase 3)
- `topCharactersBySpec` client method (Phase 3)
- Pruning of old `seeded_runs` rows (built-in `seeded_at` index leaves room; pruning is a future-future task)

---

## Pre-flight: API shape (verified live, 2026-05-03)

```
GET /api/v1/mythic-plus/runs?season=season-mn-1&region=eu&page=0

{
  "rankings": [                      // exactly 20 rows when full page
    {
      "rank": 1,
      "score": 518.2,
      "run": {
        "keystone_run_id": 20122735, // INT — our dedupe key
        "season": "season-mn-1",
        "mythic_level": 22,
        "completed_at": "2026-05-01T14:50:15.000Z",
        "dungeon": { ... },           // we ignore (cascade gets dungeon data via member sync)
        "roster": [                   // exactly 5
          {
            "character": {
              "name": "Зуджадах",     // can be non-ASCII; Blizzard sync handles UTF-8
              "realm": { "slug": "howling-fjord", "name": "Howling Fjord", ... },
              "region": { "slug": "eu", ... },
              "level": 90,
              ...
            },
            ...
          },
          ... 4 more
        ]
      }
    },
    ... 19 more
  ],
  "leaderboard_url": "...",
  "params": { ... }
}
```

Empty page = `rankings: []`. We stop pagination on empty.

---

## File Structure

**Create:**
- `backend/database/migrations/2026_05_03_200000_create_seeded_runs_table.php`
- `backend/app/Models/SeededRun.php`
- `backend/app/Services/RaiderIO/DTO/SeedCharacterRef.php`
- `backend/app/Services/RaiderIO/DTO/SeedRunRef.php`
- `backend/tests/fixtures/raiderio/top-runs-eu.json`
- `backend/tests/fixtures/raiderio/top-runs-eu-page-2.json`
- `backend/tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php` (new file — keeps existing `RaiderIOClientTest.php` focused on guilds)
- `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php`
- `backend/tests/Feature/Console/RaiderIOSeedCommandRunsTest.php`

**Modify:**
- `backend/config/raiderio.php` — add `phase.runs_pages_per_region`
- `backend/app/Services/RaiderIO/RaiderIOClient.php` — add `topRuns()` method
- `backend/app/Services/RaiderIO/RaiderIOSeeder.php` — add `seedRuns()` method
- `backend/app/Services/RaiderIO/DTO/SeedOptions.php` — add `teammateCrawl: bool` field
- `backend/app/Console/Commands/RaiderIOSeed.php` — wire `--phase=runs`
- `backend/app/Blizzard/Jobs/SyncCharacterData.php` — add `bool $forceTeammateCrawl` constructor param + use it in `dispatchTeammateCrawl()`
- `backend/.env.example` — add `RAIDERIO_SEED_RUNS_PAGES_PER_REGION`
- `backend/CLAUDE.md` — document `--phase=runs`, the `seeded_runs` ledger, and the `forceTeammateCrawl` mechanism

---

## Task 1: Migration for `seeded_runs` table

**Files:**
- Create: `backend/database/migrations/2026_05_03_200000_create_seeded_runs_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seeded_runs', function (Blueprint $table) {
            // raider.io's keystone_run_id — our dedupe key
            $table->bigInteger('keystone_run_id')->primary();
            $table->string('region', 8);
            $table->timestamp('seeded_at')->useCurrent();

            // For future cleanup: prune rows by age
            $table->index('seeded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeded_runs');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
docker compose exec app php artisan migrate
```

Expected: `seeded_runs` row in migration output, no errors.

- [ ] **Step 3: Verify schema**

```bash
docker compose exec app php artisan tinker --execute="var_dump(\Illuminate\Support\Facades\Schema::getColumnListing('seeded_runs'));"
```

Expected: `array(3) { [0]=> string(15) "keystone_run_id" [1]=> string(6) "region" [2]=> string(9) "seeded_at" }`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_03_200000_create_seeded_runs_table.php
git commit -m "feat(raiderio): add seeded_runs dedupe ledger migration"
```

---

## Task 2: `SeededRun` model

**Files:**
- Create: `backend/app/Models/SeededRun.php`

- [ ] **Step 1: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeededRun extends Model
{
    protected $table = 'seeded_runs';

    protected $primaryKey = 'keystone_run_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'keystone_run_id',
        'region',
        'seeded_at',
    ];

    protected function casts(): array
    {
        return [
            'keystone_run_id' => 'integer',
            'seeded_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 2: Smoke-test the model**

```bash
docker compose exec app php artisan tinker --execute="\App\Models\SeededRun::create(['keystone_run_id' => 999999999, 'region' => 'eu']); echo \App\Models\SeededRun::find(999999999)->region; \App\Models\SeededRun::where('keystone_run_id', 999999999)->delete();"
```

Expected: prints `eu`, no errors. Test row deleted afterwards.

- [ ] **Step 3: Commit**

```bash
git add app/Models/SeededRun.php
git commit -m "feat(raiderio): add SeededRun model"
```

---

## Task 3: `SeedCharacterRef` DTO

**Files:**
- Create: `backend/app/Services/RaiderIO/DTO/SeedCharacterRef.php`

This DTO has the same shape as `SeedGuildRef` (region + realm + name), but a different semantic identity (it's a *character* reference, not a guild). Keeping them as two distinct types avoids accidental cross-use and reads more clearly at call sites.

- [ ] **Step 1: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedCharacterRef
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
git add app/Services/RaiderIO/DTO/SeedCharacterRef.php
git commit -m "feat(raiderio): add SeedCharacterRef DTO"
```

---

## Task 4: `SeedRunRef` DTO

**Files:**
- Create: `backend/app/Services/RaiderIO/DTO/SeedRunRef.php`

- [ ] **Step 1: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedRunRef
{
    /**
     * @param  list<SeedCharacterRef>  $members
     */
    public function __construct(
        public int $keystoneRunId,
        public string $region,
        public array $members,
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/RaiderIO/DTO/SeedRunRef.php
git commit -m "feat(raiderio): add SeedRunRef DTO"
```

---

## Task 5: Add `teammateCrawl` to `SeedOptions`

**Files:**
- Modify: `backend/app/Services/RaiderIO/DTO/SeedOptions.php`

- [ ] **Step 1: Update the DTO**

Replace the contents of `app/Services/RaiderIO/DTO/SeedOptions.php` with:

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
        public bool $teammateCrawl = false,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            regions: (array) config('raiderio.regions'),
            limit: (int) config('raiderio.phase.guilds_per_region'),
            teammateCrawl: (bool) config('raiderio.teammate_crawl_during_seed'),
        );
    }

    public function withOverrides(
        ?array $regions = null,
        ?int $limit = null,
        ?bool $force = null,
        ?bool $dryRun = null,
        ?bool $teammateCrawl = null,
    ): self {
        return new self(
            regions: $regions ?? $this->regions,
            limit: $limit ?? $this->limit,
            force: $force ?? $this->force,
            dryRun: $dryRun ?? $this->dryRun,
            teammateCrawl: $teammateCrawl ?? $this->teammateCrawl,
        );
    }
}
```

- [ ] **Step 2: Run existing seeder + command tests to verify no regression**

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php tests/Feature/Console/RaiderIOSeedCommandTest.php
```

Expected: all PASS (existing tests don't pass `teammateCrawl` — defaults to `false`, no behavior change).

- [ ] **Step 3: Commit**

```bash
git add app/Services/RaiderIO/DTO/SeedOptions.php
git commit -m "feat(raiderio): add teammateCrawl flag to SeedOptions"
```

---

## Task 6: Add `runs_pages_per_region` config

**Files:**
- Modify: `backend/config/raiderio.php`

- [ ] **Step 1: Update the `phase` block**

In `config/raiderio.php`, change the `phase` block from:

```php
    'phase' => [
        'guilds_per_region' => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
    ],
```

to:

```php
    'phase' => [
        'guilds_per_region' => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
        'runs_pages_per_region' => (int) env('RAIDERIO_SEED_RUNS_PAGES_PER_REGION', 5),
    ],
```

- [ ] **Step 2: Verify it loads**

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan config:show raiderio | grep runs_pages
```

Expected: `runs_pages_per_region ......... 5`

- [ ] **Step 3: Commit**

```bash
git add config/raiderio.php
git commit -m "feat(raiderio): add runs_pages_per_region config"
```

---

## Task 7: `RaiderIOClient::topRuns` — happy path

**Files:**
- Create: `backend/tests/fixtures/raiderio/top-runs-eu.json`
- Create: `backend/tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php`
- Modify: `backend/app/Services/RaiderIO/RaiderIOClient.php`

### Step 1: Create the fixture

`backend/tests/fixtures/raiderio/top-runs-eu.json` — 3 runs, each with 5 members (lean shape, only fields we consume):

```json
{
  "rankings": [
    {
      "rank": 1,
      "score": 518.2,
      "run": {
        "keystone_run_id": 1001,
        "roster": [
          { "character": { "name": "Alice", "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
          { "character": { "name": "Bob",   "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
          { "character": { "name": "Cara",  "realm": { "slug": "kazzak"      }, "region": { "slug": "eu" } } },
          { "character": { "name": "Dan",   "realm": { "slug": "draenor"     }, "region": { "slug": "eu" } } },
          { "character": { "name": "Eve",   "realm": { "slug": "stormscale"  }, "region": { "slug": "eu" } } }
        ]
      }
    },
    {
      "rank": 2,
      "score": 510.1,
      "run": {
        "keystone_run_id": 1002,
        "roster": [
          { "character": { "name": "Frank", "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
          { "character": { "name": "Gina",  "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
          { "character": { "name": "Hank",  "realm": { "slug": "kazzak"      }, "region": { "slug": "eu" } } },
          { "character": { "name": "Ivy",   "realm": { "slug": "draenor"     }, "region": { "slug": "eu" } } },
          { "character": { "name": "Jack",  "realm": { "slug": "stormscale"  }, "region": { "slug": "eu" } } }
        ]
      }
    },
    {
      "rank": 3,
      "score": 505.0,
      "run": {
        "keystone_run_id": 1003,
        "roster": [
          { "character": { "name": "Kara",  "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
          { "character": { "name": "Leo",   "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
          { "character": { "name": "Mia",   "realm": { "slug": "kazzak"      }, "region": { "slug": "eu" } } },
          { "character": { "name": "Noah",  "realm": { "slug": "draenor"     }, "region": { "slug": "eu" } } },
          { "character": { "name": "Olga",  "realm": { "slug": "stormscale"  }, "region": { "slug": "eu" } } }
        ]
      }
    }
  ]
}
```

### Step 2: Write the failing test

Create `backend/tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedRunRef;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientRunsTest extends TestCase
{
    public function test_top_runs_yields_run_refs_with_members(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        Http::fake([
            'raider.io/api/v1/mythic-plus/runs*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);

        $runs = iterator_to_array($client->topRuns('eu', 'season-mn-1', 1), preserve_keys: false);

        $this->assertCount(3, $runs);
        $this->assertInstanceOf(SeedRunRef::class, $runs[0]);
        $this->assertSame(1001, $runs[0]->keystoneRunId);
        $this->assertSame('eu', $runs[0]->region);
        $this->assertCount(5, $runs[0]->members);
        $this->assertInstanceOf(SeedCharacterRef::class, $runs[0]->members[0]);
        $this->assertSame('Alice', $runs[0]->members[0]->name);
        $this->assertSame('tarren-mill', $runs[0]->members[0]->realmSlug);
        $this->assertSame('eu', $runs[0]->members[0]->region);
        $this->assertSame(1003, $runs[2]->keystoneRunId);
    }
}
```

### Step 3: Run the test — expect fail

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php
```

Expected: FAIL — `Method topRuns does not exist on App\Services\RaiderIO\RaiderIOClient`.

### Step 4: Implement `topRuns()`

In `app/Services/RaiderIO/RaiderIOClient.php`, add this method below `topGuilds()` (above `currentRaidSlug()`):

```php
    /**
     * Yields top mythic+ runs for the given region+season.
     * `pages` is a fixed page count (1 page = 20 runs).
     *
     * @return Generator<int, SeedRunRef>
     */
    public function topRuns(string $region, string $season, int $pages): Generator
    {
        for ($page = 0; $page < $pages; $page++) {
            $response = $this->get('/mythic-plus/runs', [
                'season' => $season,
                'region' => $region,
                'page' => $page,
            ]);

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
                    $members[] = new SeedCharacterRef(
                        region: $regionSlug,
                        realmSlug: $realmSlug,
                        name: $name,
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
```

Add the new imports at the top:

```php
use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedRunRef;
```

### Step 5: Run the test — expect pass

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php
```

Expected: PASS.

### Step 6: Commit

```bash
git add tests/fixtures/raiderio/top-runs-eu.json tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php app/Services/RaiderIO/RaiderIOClient.php
git commit -m "feat(raiderio): add RaiderIOClient::topRuns happy path"
```

---

## Task 8: `RaiderIOClient::topRuns` pagination + stops on empty page

**Files:**
- Create: `backend/tests/fixtures/raiderio/top-runs-eu-page-2.json`
- Modify: `backend/tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php`

### Step 1: Create the page-2 fixture (1 run, then stop)

`backend/tests/fixtures/raiderio/top-runs-eu-page-2.json`:

```json
{
  "rankings": [
    {
      "rank": 21,
      "score": 480.0,
      "run": {
        "keystone_run_id": 2001,
        "roster": [
          { "character": { "name": "P21a", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
          { "character": { "name": "P21b", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
          { "character": { "name": "P21c", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
          { "character": { "name": "P21d", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } },
          { "character": { "name": "P21e", "realm": { "slug": "draenor" }, "region": { "slug": "eu" } } }
        ]
      }
    }
  ]
}
```

### Step 2: Append failing test for pagination

Append to `RaiderIOClientRunsTest.php`:

```php
public function test_top_runs_paginates_pages_until_pages_count_or_empty(): void
{
    $page0 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
    $page1 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu-page-2.json')), true);
    $emptyPage = ['rankings' => []];

    Http::fake(function ($request) use ($page0, $page1, $emptyPage) {
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $q);
        $page = (int) ($q['page'] ?? 0);
        return match ($page) {
            0 => Http::response($page0, 200),
            1 => Http::response($page1, 200),
            default => Http::response($emptyPage, 200),
        };
    });

    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    // Request 3 pages — page 0 = 3 runs, page 1 = 1 run, page 2 onwards empty (we still ask page 2)
    $runs = iterator_to_array($client->topRuns('eu', 'season-mn-1', 3), preserve_keys: false);

    $this->assertCount(4, $runs); // 3 + 1
    Http::assertSentCount(3); // pages 0, 1, 2 — last call returned empty, generator stopped
}

public function test_top_runs_stops_immediately_on_first_empty_page(): void
{
    Http::fake(['raider.io/*' => Http::response(['rankings' => []], 200)]);

    $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

    $runs = iterator_to_array($client->topRuns('eu', 'season-mn-1', 5), preserve_keys: false);

    $this->assertCount(0, $runs);
    Http::assertSentCount(1);
}
```

### Step 3: Run — expect pass (no implementation change needed; pagination loop already in client)

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php
```

Expected: 3 tests PASS.

### Step 4: Commit

```bash
git add tests/fixtures/raiderio/top-runs-eu-page-2.json tests/Unit/Services/RaiderIO/RaiderIOClientRunsTest.php
git commit -m "test(raiderio): cover topRuns pagination + empty-page stop"
```

---

## Task 9: `RaiderIOSeeder::seedRuns` — happy path + dedupe ledger

**Files:**
- Create: `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php`
- Modify: `backend/app/Services/RaiderIO/RaiderIOSeeder.php`

### Step 1: Write the failing happy-path test

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\SeededRun;
use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedRunRef;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RaiderIOSeederRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_seed_runs_dispatches_full_per_member_and_persists_ledger(): void
    {
        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->with('eu', 'season-mn-1', 1)->andReturn((function () {
            yield new SeedRunRef(
                keystoneRunId: 1001,
                region: 'eu',
                members: [
                    new SeedCharacterRef('eu', 'tarren-mill', 'Alice'),
                    new SeedCharacterRef('eu', 'tarren-mill', 'Bob'),
                ],
            );
            yield new SeedRunRef(
                keystoneRunId: 1002,
                region: 'eu',
                members: [
                    new SeedCharacterRef('eu', 'kazzak', 'Cara'),
                ],
            );
        })());

        $seeder = app(RaiderIOSeeder::class);
        $opts = new SeedOptions(regions: ['eu'], limit: 1);

        $report = $seeder->seedRuns($opts);

        // 3 unique character dispatches across 2 runs
        Bus::assertDispatched(SyncCharacterData::class, 3);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) =>
            $j->name === 'Alice' && $j->depth === SyncDepth::Full);

        $this->assertSame(2, $report->considered);  // 2 runs considered
        $this->assertSame(3, $report->dispatched);  // 3 character syncs dispatched
        $this->assertSame(0, $report->skippedDedupe);

        $this->assertTrue(SeededRun::where('keystone_run_id', 1001)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1002)->exists());
    }

    public function test_seed_runs_skips_already_seeded_runs(): void
    {
        SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);

        $client = $this->mock(RaiderIOClient::class);
        $client->shouldReceive('topRuns')->andReturn((function () {
            yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'Alice')]);
            yield new SeedRunRef(1002, 'eu', [new SeedCharacterRef('eu', 'kazzak', 'Bob')]);
        })());

        $seeder = app(RaiderIOSeeder::class);
        $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

        // Run 1001 already seeded → skipped, no dispatches for its members
        // Run 1002 fresh → 1 dispatch
        Bus::assertDispatched(SyncCharacterData::class, 1);
        Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Bob');

        $this->assertSame(2, $report->considered);
        $this->assertSame(1, $report->dispatched);
        $this->assertSame(1, $report->skippedDedupe);
    }
}
```

### Step 2: Run the tests — expect fail

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php
```

Expected: FAIL — `Method seedRuns does not exist on App\Services\RaiderIO\RaiderIOSeeder`.

### Step 3: Implement `seedRuns()`

In `app/Services/RaiderIO/RaiderIOSeeder.php`, add this method after `seedGuilds()`:

```php
    public function seedRuns(SeedOptions $opts): SeedReport
    {
        $report = new SeedReport(phase: 'runs', regions: $opts->regions);
        $season = (string) config('raiderio.season');

        Log::info('raiderio.seed.start', [
            'phase' => 'runs',
            'regions' => $opts->regions,
            'pages' => $opts->limit,
            'season' => $season,
        ]);

        foreach ($opts->regions as $region) {
            try {
                foreach ($this->client->topRuns($region, $season, $opts->limit) as $runRef) {
                    $report->considered++;

                    $inserted = DB::table('seeded_runs')->insertOrIgnore([
                        'keystone_run_id' => $runRef->keystoneRunId,
                        'region' => $region,
                        'seeded_at' => now(),
                    ]);

                    if ($inserted === 0) {
                        $report->skippedDedupe++;
                        continue;
                    }

                    foreach ($runRef->members as $memberRef) {
                        if (! $opts->force && $this->characterIsFresh($memberRef)) {
                            $report->skippedTtl++;
                            continue;
                        }

                        if ($opts->dryRun) {
                            $report->dispatched++;
                            continue;
                        }

                        SyncCharacterData::dispatch(
                            region: $memberRef->region,
                            realm: $memberRef->realmSlug,
                            name: $memberRef->name,
                            depth: SyncDepth::Full,
                            forceTeammateCrawl: $opts->teammateCrawl,
                        );
                        $report->dispatched++;
                    }
                }
            } catch (RaiderIOException $e) {
                $report->errors++;
                Log::warning('raiderio.seed.error', [
                    'phase' => 'runs',
                    'region' => $region,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('raiderio.seed.complete', $report->toArray());

        return $report;
    }

    protected function characterIsFresh(SeedCharacterRef $ref): bool
    {
        $existing = Character::byIdentity($ref->name, $ref->realmSlug, $ref->region)->first();
        if ($existing === null || $existing->updated_at === null) {
            return false;
        }
        $ttl = (int) config('raiderio.character_resync_ttl', 12 * 3600);
        return $existing->updated_at->isAfter(now()->subSeconds($ttl));
    }
```

Add these imports at the top of the file (next to the existing `use` lines):

```php
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use App\Services\RaiderIO\DTO\SeedCharacterRef;
use Illuminate\Support\Facades\DB;
```

**Note on `forceTeammateCrawl` in the dispatch:** Task 11 below adds this parameter to `SyncCharacterData`'s constructor. If you implement Task 9 before Task 11, the `forceTeammateCrawl: $opts->teammateCrawl` line will fail at runtime with "Unknown named parameter". Either implement Task 11 first, OR comment out the `forceTeammateCrawl: ...` line, complete Task 11, then uncomment it. Recommendation: **do Task 11 immediately after this step** (out of TDD order is fine here — Task 11 is a small additive change to an existing class).

### Step 4: Run all RaiderIO tests

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/
```

Expected: all PASS — guild tests still green (no behavior change), runs tests now green. **However:** if Task 11 is not yet done, the runs tests will fail at the `forceTeammateCrawl: ...` named arg line. Skip ahead to Task 11 before running this step.

### Step 5: Commit

```bash
git add tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php app/Services/RaiderIO/RaiderIOSeeder.php
git commit -m "feat(raiderio): add RaiderIOSeeder::seedRuns with dedupe ledger"
```

---

## Task 10: `seedRuns` — TTL skip + force + dry-run

**Files:**
- Modify: `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php`

### Step 1: Append three more tests

```php
public function test_seed_runs_skips_fresh_characters(): void
{
    \App\Models\Character::factory()->create([
        'name' => 'fresh-bob',  // factory lowercases random firstName; we pass explicitly
        'realm' => 'tarren-mill',
        'region' => 'eu',
    ]);
    // Force updated_at to be recent
    \App\Models\Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
        ->update(['updated_at' => now()->subMinutes(5)]);

    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topRuns')->andReturn((function () {
        yield new SeedRunRef(
            keystoneRunId: 1001,
            region: 'eu',
            members: [
                new SeedCharacterRef('eu', 'tarren-mill', 'fresh-bob'),
                new SeedCharacterRef('eu', 'kazzak', 'Stale'),
            ],
        );
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

    Bus::assertDispatched(SyncCharacterData::class, 1);
    Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Stale');
    Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'fresh-bob');

    $this->assertSame(1, $report->dispatched);
    $this->assertSame(1, $report->skippedTtl);
}

public function test_seed_runs_force_bypasses_character_ttl_skip(): void
{
    \App\Models\Character::factory()->create([
        'name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu',
    ]);
    \App\Models\Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
        ->update(['updated_at' => now()->subMinutes(5)]);

    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topRuns')->andReturn((function () {
        yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'fresh-bob')]);
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, force: true));

    Bus::assertDispatched(SyncCharacterData::class, 1);
    $this->assertSame(1, $report->dispatched);
    $this->assertSame(0, $report->skippedTtl);
}

public function test_seed_runs_dry_run_dispatches_nothing_but_writes_ledger(): void
{
    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topRuns')->andReturn((function () {
        yield new SeedRunRef(1001, 'eu', [
            new SeedCharacterRef('eu', 'tarren-mill', 'A'),
            new SeedCharacterRef('eu', 'tarren-mill', 'B'),
        ]);
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, dryRun: true));

    Bus::assertNothingDispatched();
    $this->assertSame(2, $report->dispatched);  // counter still increments under dry-run
    // Dry-run intentionally still writes the ledger row — once dispatched, runs are immutable
    $this->assertTrue(\App\Models\SeededRun::where('keystone_run_id', 1001)->exists());
}
```

### Step 2: Run all runs-seeder tests

```bash
docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php
```

Expected: 5 tests PASS (existing implementation already covers these branches).

### Step 3: Commit

```bash
git add tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php
git commit -m "test(raiderio): cover seedRuns TTL skip, force, dry-run"
```

---

## Task 11: Add `forceTeammateCrawl` to `SyncCharacterData`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncCharacterData.php`
- Create: `backend/tests/Feature/Blizzard/Jobs/SyncCharacterDataForceTeammateCrawlTest.php`

This is the smallest behavior-changing piece. The seeder needs a way to override the global `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` config flag for *this specific dispatch only*. Adding a constructor param keeps the override per-job and serializable across the queue.

### Step 1: Modify the constructor

In `app/Blizzard/Jobs/SyncCharacterData.php`, find the `__construct` (around line 64) and add a new readonly param at the end:

**Before:**
```php
public function __construct(
    public readonly string $region,
    public readonly string $realm,
    public readonly string $name,
    public readonly SyncDepth $depth = SyncDepth::Standard,
    public readonly ?int $userId = null,
    public readonly int $crawlDepth = 0,
) {
    // ... existing onQueue logic
}
```

**After:**
```php
public function __construct(
    public readonly string $region,
    public readonly string $realm,
    public readonly string $name,
    public readonly SyncDepth $depth = SyncDepth::Standard,
    public readonly ?int $userId = null,
    public readonly int $crawlDepth = 0,
    public readonly bool $forceTeammateCrawl = false,
) {
    // ... existing onQueue logic — unchanged
}
```

### Step 2: Use the flag in `dispatchTeammateCrawl()`

In the same file, find `dispatchTeammateCrawl` (around line 771). Replace the early-return guard:

**Before:**
```php
if (! config('blizzard.sync.teammate_crawl_enabled')) {
    return;
}
```

**After:**
```php
// Phase 2 wiring: a seed-originated job (forceTeammateCrawl=true) overrides
// the global kill-switch. Crawled descendants always get forceTeammateCrawl=false
// (see self::dispatch call at the end of this method), so the override does not
// recurse — nested crawls obey the global config flag.
if (! $this->forceTeammateCrawl && ! config('blizzard.sync.teammate_crawl_enabled')) {
    return;
}
```

(No change to the `self::dispatch` call further down — descendants get `forceTeammateCrawl: false` by default since the param isn't passed.)

### Step 3: Write the failing test

Create `backend/tests/Feature/Blizzard/Jobs/SyncCharacterDataForceTeammateCrawlTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use Tests\TestCase;

class SyncCharacterDataForceTeammateCrawlTest extends TestCase
{
    public function test_constructor_default_force_teammate_crawl_is_false(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'Test',
            depth: SyncDepth::Full,
        );

        $this->assertFalse($job->forceTeammateCrawl);
    }

    public function test_constructor_accepts_force_teammate_crawl_true(): void
    {
        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'Test',
            depth: SyncDepth::Full,
            forceTeammateCrawl: true,
        );

        $this->assertTrue($job->forceTeammateCrawl);
    }

    public function test_force_teammate_crawl_param_is_serializable(): void
    {
        // Ensure the readonly param survives queue serialization round-trip
        $original = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'Test',
            depth: SyncDepth::Full,
            forceTeammateCrawl: true,
        );

        $serialized = serialize($original);
        $restored = unserialize($serialized);

        $this->assertTrue($restored->forceTeammateCrawl);
    }
}
```

### Step 4: Run the tests — expect pass (additive change)

```bash
docker compose exec app ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncCharacterDataForceTeammateCrawlTest.php
```

Expected: 3 tests PASS.

### Step 5: Confirm no existing tests regressed

```bash
docker compose exec app ./vendor/bin/phpunit tests/Feature/Blizzard/ tests/Unit/Blizzard/
```

Expected: all PASS.

### Step 6: Commit

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php tests/Feature/Blizzard/Jobs/SyncCharacterDataForceTeammateCrawlTest.php
git commit -m "feat(blizzard): add forceTeammateCrawl override on SyncCharacterData"
```

---

## Task 12: Wire `--phase=runs` in artisan command

**Files:**
- Modify: `backend/app/Console/Commands/RaiderIOSeed.php`

### Step 1: Update the handle() flow

In `app/Console/Commands/RaiderIOSeed.php`, modify the `handle()` method.

**Before:**
```php
if ($phase !== 'guilds') {
    $this->error("Phase '$phase' not yet implemented (phase 1 ships guilds only).");
    return self::FAILURE;
}

$opts = $this->buildOptions();
$report = $seeder->seedGuilds($opts);
```

**After:**
```php
if ($phase === 'characters' || $phase === 'all') {
    $this->error("Phase '$phase' not yet implemented (phase 3 deliverable).");
    return self::FAILURE;
}

$opts = $this->buildOptions($phase);
$report = match ($phase) {
    'guilds' => $seeder->seedGuilds($opts),
    'runs' => $seeder->seedRuns($opts),
};
```

### Step 2: Update buildOptions to take a phase argument

`buildOptions()` currently always uses `guilds_per_region` for the limit default. Update it so `runs` reads from `runs_pages_per_region`:

**Replace** the existing `protected function buildOptions(): SeedOptions` body with:

```php
    protected function buildOptions(string $phase): SeedOptions
    {
        $regions = $this->option('regions')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('regions')))))
            : (array) config('raiderio.regions');

        $defaultLimit = match ($phase) {
            'runs' => (int) config('raiderio.phase.runs_pages_per_region'),
            default => (int) config('raiderio.phase.guilds_per_region'),
        };
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : $defaultLimit;

        return new SeedOptions(
            regions: $regions,
            limit: $limit,
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
            teammateCrawl: (bool) config('raiderio.teammate_crawl_during_seed'),
        );
    }
```

### Step 3: Write the failing feature test

Create `backend/tests/Feature/Console/RaiderIOSeedCommandRunsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\SeededRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOSeedCommandRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);
    }

    public function test_phase_runs_dispatches_full_per_member_and_records_ledger(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'runs',
            '--limit' => 1,
            '--regions' => 'eu',
        ])->assertSuccessful();

        // Fixture has 3 runs × 5 members each = 15 dispatches
        Bus::assertDispatched(SyncCharacterData::class, 15);

        // All three runs in the ledger
        $this->assertSame(3, SeededRun::count());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1001)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1002)->exists());
        $this->assertTrue(SeededRun::where('keystone_run_id', 1003)->exists());
    }

    public function test_phase_runs_dry_run_dispatches_nothing(): void
    {
        $this->artisan('raiderio:seed', [
            '--phase' => 'runs',
            '--limit' => 1,
            '--regions' => 'eu',
            '--dry-run' => true,
        ])->assertSuccessful();

        Bus::assertNothingDispatched();
        // Ledger still gets written under dry-run
        $this->assertSame(3, SeededRun::count());
    }
}
```

### Step 4: Run the failing tests

```bash
docker compose exec app ./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedCommandRunsTest.php
```

Expected: tests run; they should now PASS since Task 9 + Task 12 together implement everything they assert.

### Step 5: Run the existing command tests too

```bash
docker compose exec app ./vendor/bin/phpunit tests/Feature/Console/
```

Expected: all PASS — including the existing `test_unsupported_phase_runs_returns_not_implemented` test from Phase 1. Wait — that test asserts `runs` returns "not yet implemented" but Phase 2 makes `runs` work! Update or delete that single test.

In `tests/Feature/Console/RaiderIOSeedCommandTest.php`, **remove** the `test_unsupported_phase_runs_returns_not_implemented` test (it's no longer accurate). Keep `test_unsupported_phase_characters_returns_not_implemented` — characters is still unimplemented in Phase 2.

### Step 6: Run the test suite again

```bash
docker compose exec app ./vendor/bin/phpunit tests/Feature/Console/
```

Expected: all PASS (no more "runs" not-implemented assertion).

### Step 7: Commit

```bash
git add app/Console/Commands/RaiderIOSeed.php tests/Feature/Console/
git commit -m "feat(raiderio): wire raiderio:seed --phase=runs"
```

---

## Task 13: Update `.env.example` and `backend/CLAUDE.md`

**Files:**
- Modify: `backend/.env.example`
- Modify: `backend/CLAUDE.md`

### Step 1: Add new env var to `.env.example`

In `backend/.env.example`, find the `# raider.io seeder` block. Add this line right after `RAIDERIO_SEED_GUILDS_PER_REGION=10`:

```ini
RAIDERIO_SEED_RUNS_PAGES_PER_REGION=5
```

### Step 2: Update `backend/CLAUDE.md` — RaiderIO Seeder section

Find the bullet that says "Phase 1 (shipped): Guilds." and replace the surrounding three bullets (Phase 1, Phase 2-3, Dedupe) with the updated phase-2-shipped versions:

**Replace:**
```markdown
- **Phase 1 (shipped): Guilds.** `php artisan raiderio:seed --phase=guilds --limit=N --regions=eu,us` pulls top mythic raiding guilds via `/guilds/static-raid-rankings`, dispatches `SyncGuildData` per guild. `SyncGuildRoster` modification then dispatches `SyncCharacterData::Full` for each roster member (TTL-gated on `Character.updated_at`). Phase-1 hardcodes the current Midnight raid slug `the-voidspire` in `RaiderIOClient::currentRaidSlug()` — bump per raid rotation.
- **Phases 2-3 (not shipped): Runs, Characters.** Specs covered in `docs/superpowers/specs/2026-05-03-raiderio-seeder-design.md`; plans pending. The `seeded_runs` table and the `topRuns` / `topCharactersBySpec` client methods do not exist yet.
- **Dedupe model (phase 1).** Guilds: existing `Guild::isRosterStale()` is reused (config/blizzard.php threshold). `--force` flag bypasses for the future "manual re-sync" UI button.
```

**With:**
```markdown
- **Phase 1 (shipped): Guilds.** `php artisan raiderio:seed --phase=guilds --limit=N --regions=eu,us` pulls top mythic raiding guilds via `/raiding/raid-rankings?raid=tier-mn-1&difficulty=mythic`, dispatches `SyncGuildData` per guild. `SyncGuildRoster` modification then dispatches `SyncCharacterData::Full` for each roster member (TTL-gated on `Character.updated_at`). Raid tier slug configurable via `RAIDERIO_CURRENT_RAID_TIER` — bump per tier rotation.
- **Phase 2 (shipped): Runs.** `php artisan raiderio:seed --phase=runs --limit=N --regions=eu,us` pulls top M+ runs from `/mythic-plus/runs?season={season}&region={r}&page={N}` (20 runs/page; `--limit` is *pages*, not runs). Each run yields 5 character refs from its roster; dispatches `SyncCharacterData::Full` per member. Dedupe via the `seeded_runs` table keyed on raider.io's `keystone_run_id` (immutable — once seeded, never re-seeded). Cascade fills `dungeon_runs` etc. via the regular Full mythic+ slice on each member's sync.
- **Phase 3 (not shipped): Characters.** Spec at `docs/superpowers/specs/2026-05-03-raiderio-seeder-design.md` §Phase 3; plan pending. The `topCharactersBySpec` client method does not exist yet.
- **Dedupe model.** Guilds: existing `Guild::isRosterStale()` (config/blizzard.php threshold). Runs: `seeded_runs` ledger keyed on `keystone_run_id` (no TTL — runs are immutable; `seeded_at` indexed for future cleanup). Characters (run members): `Character.updated_at` against `RAIDERIO_SEED_CHAR_TTL` (default 12h). `--force` flag bypasses TTL gates; reserved for the future "manual re-sync" UI button.
```

Find the bullet that says "Teammate crawl during seed." and replace it:

**Replace:**
```markdown
- **Teammate crawl during seed.** `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` env var is documented but **not yet wired** into the seed loop in phase 1 — phase 2 (Runs) will wire it. Independent of the global `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` flag.
```

**With:**
```markdown
- **Teammate crawl during seed.** `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED` (default false) overrides the global `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` for seed-originated dispatches. Wired via a new `bool $forceTeammateCrawl` constructor param on `SyncCharacterData` — the seeder passes `forceTeammateCrawl: $opts->teammateCrawl` to each Full dispatch. Crawled descendants get `forceTeammateCrawl: false` (the override does not recurse), so nested crawls fall back to the global flag. Used by phase 2 (Runs) and phase 3 (Characters) — phase 1 (Guilds) does not pass the flag because guild roster fan-out runs from `SyncGuildRoster`, not the seeder. Flip on for a deliberate "blow it out" run; expect a small seed to balloon to thousands of characters via depth-2 crawl.
```

In the **Env vars** subsection, change:
```markdown
`RAIDERIO_SEED_GUILDS_PER_REGION` (default 10),
```
to:
```markdown
`RAIDERIO_SEED_GUILDS_PER_REGION` (default 10),
`RAIDERIO_SEED_RUNS_PAGES_PER_REGION` (default 5; one page = 20 runs = 100 character dispatches),
```

In the **Common invocations** subsection, add after the existing block:
````markdown
```bash
# Phase 2 — Runs (top M+ runs per region)
php artisan raiderio:seed --phase=runs --limit=2 --regions=eu --dry-run
php artisan raiderio:seed --phase=runs --limit=5 --regions=eu,us
```
````

### Step 3: Commit

```bash
git add .env.example CLAUDE.md
git commit -m "docs(raiderio): document phase 2 (runs) in CLAUDE.md and .env.example"
```

---

## Task 14: Smoke test on EU + memory check

**Goal:** verify the runs phase end-to-end against real raider.io and Blizzard, and confirm memory profile stays in line with phase 1.

### Step 1: Restart Horizon

```bash
docker compose restart horizon
sleep 4
docker compose ps horizon
```

Expected: `Up X seconds (health: starting)` then healthy.

### Step 2: Capture baseline

```bash
docker stats --no-stream --format "{{.Name}}: {{.MemUsage}}" guild-service-be-v2-app-1 guild-service-be-v2-horizon-1 guild-service-be-v2-postgres-1 guild-service-be-v2-redis-1

docker compose exec app php artisan tinker --execute="echo 'chars='.\App\Models\Character::count().' runs='.\App\Models\DungeonRun::count().' seeded_runs='.\App\Models\SeededRun::count();"
```

Note the numbers.

### Step 3: Dry-run to verify command shape

```bash
docker compose exec app php artisan raiderio:seed --phase=runs --limit=1 --regions=eu --dry-run
```

Expected: report table prints `considered=20 dispatched=100 errors=0` (1 page = 20 runs × 5 members; dry-run still increments dispatched counter). `SeededRun::count()` should now be 20 (ledger writes happen even under dry-run).

### Step 4: Real run with limit=2 EU

```bash
docker compose exec app php artisan raiderio:seed --phase=runs --limit=2 --regions=eu
```

Expected: dispatches in seconds. Tail Horizon output:

```bash
docker compose logs --tail=20 horizon | tail
```

### Step 5: Watch cascade for 5 minutes

```bash
for i in 1 2 3 4 5; do
  sleep 60
  CHARS=$(docker compose exec -T app sh -c "php artisan tinker --execute=\"echo \\App\\Models\\Character::count();\"" | tail -1)
  RUNS=$(docker compose exec -T app sh -c "php artisan tinker --execute=\"echo \\App\\Models\\DungeonRun::count();\"" | tail -1)
  HZ=$(docker stats --no-stream --format "{{.MemUsage}}" guild-service-be-v2-horizon-1)
  echo "M+${i}: chars=$CHARS dungeon_runs=$RUNS horizon=$HZ"
done
```

Expected: characters grow steadily (~2-3/sec sustained per phase 1 measurements). `dungeon_runs` should grow as Full sync's mythic+ slice writes the runs the synced characters appeared in. Horizon memory peaks around 500-600 MB (matches phase 1).

### Step 6: Verify dedupe re-run

```bash
docker compose exec app php artisan raiderio:seed --phase=runs --limit=2 --regions=eu
```

Expected: `considered=40 dispatched=0 skipped_dedupe=40 errors=0` — all runs already in ledger, none re-dispatched.

### Step 7: Verify `--force` does NOT bypass run-ledger dedupe

`--force` only bypasses the *character* TTL gate, not the run-ledger. Once a run is seeded, it's permanently deduped (runs are immutable historical events). Verify:

```bash
docker compose exec app php artisan raiderio:seed --phase=runs --limit=2 --regions=eu --force
```

Expected: same `skipped_dedupe=40` — `--force` doesn't touch the ledger. (Character TTL bypass is only relevant for runs whose ledger entry was just inserted.)

If you want to re-seed the same runs from scratch (e.g., during testing), truncate the ledger:
```bash
docker compose exec app php artisan tinker --execute="\App\Models\SeededRun::truncate();"
```

### Step 8: Mark task complete

If all steps pass and the cascade is producing characters (and `dungeon_runs` rows when chars hit the M+ slice), Phase 2 is done.

---

## Phase 3 — placeholder

Phase 3 (Characters) ships `--phase=characters`: pulls top characters per class/spec from `/mythic-plus/leaderboards`, dispatches `SyncCharacterData::Full` per character. Spec at `docs/superpowers/specs/2026-05-03-raiderio-seeder-design.md`. Plan to be written after Phase 2 ships and the `seeded_runs` ledger has run in production for at least one cycle.

What Phase 2 leaves in place for Phase 3:
- `SeedCharacterRef` DTO is reusable.
- `RaiderIOClient` is structured to accept a third `topCharactersBySpec()` method alongside `topGuilds` / `topRuns`.
- `RaiderIOSeeder` accepts a third `seedCharacters()` method alongside `seedGuilds` / `seedRuns`.
- `RaiderIOSeed` artisan command's `--phase=characters` already validates but errors with "not yet implemented" — Phase 3 swaps that out for a real handler.
- The `forceTeammateCrawl` mechanism is in place, fully tested, and ready for Phase 3 to dispatch through.
