# RaiderIO Seed Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand raider.io discovery so the DB continuously accumulates top M+ players: deeper global run ladders, new per-dungeon ladders, deeper guild rankings — with dispatch caps so the Blizzard queue can't be flooded.

**Architecture:** All changes stay inside the existing lean discovery layer (`app/Services/RaiderIO/`): `RaiderIOClient` gains a `dungeon` filter + a season-dungeon-slug helper, `RaiderIOSeeder` gains a per-dungeon ladder loop, per-region dispatch caps, and an in-run duplicate-member guard. Everything downstream (Blizzard sync jobs, TTL gates, teammate crawl) is untouched. Breadth remains env-tunable; new features default OFF (0) so dev/tests keep current behavior — prod env turns them on.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (SQLite in-memory, `Http::fake`, `Bus::fake`).

## Background (verified against live API 2026-07-28)

- Current prod: `RAIDERIO_SEED_GUILDS_PER_REGION=25`, `RAIDERIO_SEED_RUNS_PAGES_PER_REGION=25`, TTL 7d, nightly 01:00 UTC. Saturated: ~97% of considered members are TTL skips.
- With `RAIDERIO_ACCESS_KEY` (already appended by `RaiderIOClient::doGet`) the `/mythic-plus/runs` `page` cap of 100 is lifted — page 1000 (rank 20,001) verified working. `dungeon=<slug>` filter verified working. `realm` param is NOT supported (400).
- `/raiding/raid-rankings` mythic depth ≈ rank 3,200 (US); we only take top 25.
- Season dungeon slugs come from `/mythic-plus/static-data?expansion_id=11` → `seasons[].dungeons[].slug` (8 dungeons in season-mn-1). Client method `mythicPlusStaticData()` already exists.
- Capacity ceiling is Blizzard, not raider.io: ~1,500 Full syncs/hour ≈ 36k/day. Caps below keep first-night dispatch ≈ 21k jobs.

## Global Constraints

- New config defaults MUST preserve current behavior (`runs_pages_per_dungeon=0` → no dungeon ladders; caps `0` → uncapped).
- Character/realm identifiers are canonicalized at the client boundary via `BlizzardIdentity` — do not change this.
- NEVER `strtolower()` a character name (mb-lowercase invariant, see backend/CLAUDE.md).
- No Claude/Anthropic attribution in commit messages (no Co-Authored-By, no "Generated with" lines).
- Tests: `composer test` or `./vendor/bin/phpunit --filter=...`; phpunit sets `RAIDERIO_BACKOFF_SLEEP_ENABLED=0` so 5xx retries don't sleep.
- After deploy, `docker compose restart horizon` is REQUIRED (opcache validate_timestamps=0).

## File Structure

- Modify: `backend/config/raiderio.php` — new phase knobs + `expansion_id`
- Modify: `backend/app/Services/RaiderIO/DTO/SeedOptions.php` — 3 new fields
- Modify: `backend/app/Services/RaiderIO/DTO/SeedReport.php` — `skippedCap` counter
- Modify: `backend/app/Console/Commands/RaiderIOSeed.php` — wire new options + report column
- Modify: `backend/app/Services/RaiderIO/RaiderIOClient.php` — `dungeon` param on `topRuns()`, new `seasonDungeonSlugs()`
- Modify: `backend/app/Services/RaiderIO/RaiderIOSeeder.php` — guild cap; runs: ladder loop, member dedupe, char cap
- Create: `backend/tests/fixtures/raiderio/static-data-expansion-11.json`
- Create: `backend/tests/fixtures/raiderio/top-runs-eu-dungeon.json`
- Create: `backend/tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php`
- Modify: `backend/tests/Feature/Console/RaiderIOSeedCommandTest.php` (guild cap test lives here)
- Modify: `backend/tests/Feature/Console/RaiderIOSeedCommandRunsTest.php` (char cap test lives here)
- Modify: `backend/docs/raiderio-seeder.md`

All paths below are relative to `backend/` (run composer/phpunit from `backend/`).

---

### Task 1: Config + DTO + report plumbing (no behavior change)

**Files:**
- Modify: `config/raiderio.php`
- Modify: `app/Services/RaiderIO/DTO/SeedOptions.php`
- Modify: `app/Services/RaiderIO/DTO/SeedReport.php`
- Modify: `app/Console/Commands/RaiderIOSeed.php`

**Interfaces:**
- Produces: `SeedOptions` gains `public int $dungeonPages = 0`, `public int $maxGuildDispatches = 0`, `public int $maxCharDispatches = 0` (constructor-promoted, in this order after `teammateCrawl`). `SeedReport` gains `public int $skippedCap = 0` and emits `skipped_cap` in `toArray()`. Config keys: `raiderio.expansion_id`, `raiderio.phase.runs_pages_per_dungeon`, `raiderio.phase.max_guild_dispatches_per_region`, `raiderio.phase.max_char_dispatches_per_region`.

- [ ] **Step 1: Extend `config/raiderio.php`**

Add after the `'current_raid_tier'` line:

```php
    // raider.io expansion_id for /mythic-plus/static-data (dungeon slug source).
    // 11 = Midnight. Bump alongside season:rollover when the expansion changes.
    'expansion_id' => (int) env('RAIDERIO_EXPANSION_ID', 11),
```

Replace the `'phase'` array with:

```php
    'phase' => [
        'guilds_per_region' => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
        'runs_pages_per_region' => (int) env('RAIDERIO_SEED_RUNS_PAGES_PER_REGION', 5),

        // Per-dungeon ladders (runs phase). 0 = disabled (global ladder only).
        // Requires RAIDERIO_ACCESS_KEY for pages > 100.
        'runs_pages_per_dungeon' => (int) env('RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON', 0),

        // Per-region, per-run dispatch caps protecting the Blizzard queue.
        // 0 = uncapped. Stale entries beyond the cap are picked up on later
        // nightly runs (natural ramp).
        'max_guild_dispatches_per_region' => (int) env('RAIDERIO_SEED_MAX_GUILD_DISPATCHES_PER_REGION', 0),
        'max_char_dispatches_per_region' => (int) env('RAIDERIO_SEED_MAX_CHAR_DISPATCHES_PER_REGION', 0),
    ],
```

- [ ] **Step 2: Extend `SeedOptions`**

New constructor signature (keep `final readonly`):

```php
    public function __construct(
        public array $regions,
        public int $limit,
        public bool $force = false,
        public bool $dryRun = false,
        public bool $teammateCrawl = false,
        public int $dungeonPages = 0,
        public int $maxGuildDispatches = 0,
        public int $maxCharDispatches = 0,
    ) {}
```

Extend `fromConfig()` to also pass:

```php
            dungeonPages: (int) config('raiderio.phase.runs_pages_per_dungeon'),
            maxGuildDispatches: (int) config('raiderio.phase.max_guild_dispatches_per_region'),
            maxCharDispatches: (int) config('raiderio.phase.max_char_dispatches_per_region'),
```

Extend `withOverrides()` with `?int $dungeonPages = null, ?int $maxGuildDispatches = null, ?int $maxCharDispatches = null` params, each falling back to `$this->...` like the existing ones.

- [ ] **Step 3: Extend `SeedReport`**

Add `public int $skippedCap = 0;` after `$skippedDedupe`, and `'skipped_cap' => $this->skippedCap,` after `'skipped_dedupe'` in `toArray()`.

- [ ] **Step 4: Wire the command**

In `RaiderIOSeed::buildOptions()`, pass the new fields from config:

```php
        return new SeedOptions(
            regions: $regions,
            limit: $limit,
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
            teammateCrawl: (bool) config('raiderio.teammate_crawl_during_seed'),
            dungeonPages: (int) config('raiderio.phase.runs_pages_per_dungeon'),
            maxGuildDispatches: (int) config('raiderio.phase.max_guild_dispatches_per_region'),
            maxCharDispatches: (int) config('raiderio.phase.max_char_dispatches_per_region'),
        );
```

In `handle()`, extend the report table:

```php
        $this->table(
            ['phase', 'regions', 'considered', 'dispatched', 'skipped_ttl', 'skipped_dedupe', 'skipped_cap', 'errors'],
            array_map(fn (SeedReport $r) => [
                $r->phase,
                implode(',', $r->regions),
                $r->considered,
                $r->dispatched,
                $r->skippedTtl,
                $r->skippedDedupe,
                $r->skippedCap,
                $r->errors,
            ], $reports)
        );
```

- [ ] **Step 5: Run the existing seeder tests — must stay green (defaults preserve behavior)**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedCommandTest.php tests/Feature/Console/RaiderIOSeedCommandRunsTest.php`
Expected: PASS (all existing tests).

- [ ] **Step 6: Style + commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "BE: raiderio seed — config/DTO plumbing for dungeon ladders + dispatch caps"
```

---

### Task 2: Client — dungeon-filtered ladders + season dungeon slugs

**Files:**
- Modify: `app/Services/RaiderIO/RaiderIOClient.php`
- Create: `tests/fixtures/raiderio/static-data-expansion-11.json`
- Test: `tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php` (created here with the client test only; seeder tests added in Task 3)

**Interfaces:**
- Produces: `topRuns(string $region, string $season, int $pages, ?string $dungeon = null): Generator` — appends `dungeon` query param when non-null. `seasonDungeonSlugs(int $expansionId, string $seasonSlug): array` — list of dungeon slug strings for that season, `[]` if season not found.
- Consumes: existing `mythicPlusStaticData(int $expansionId)` and `get()`.

- [ ] **Step 1: Create the static-data fixture**

`tests/fixtures/raiderio/static-data-expansion-11.json` — trimmed to what we read (two dungeons keeps downstream test math small):

```json
{
    "seasons": [
        {
            "slug": "season-mn-1",
            "dungeons": [
                { "id": 16395, "slug": "maisara-caverns", "challenge_mode_id": 560 },
                { "id": 4813, "slug": "pit-of-saron", "challenge_mode_id": 561 }
            ]
        },
        {
            "slug": "season-mn-0",
            "dungeons": [
                { "id": 9999, "slug": "should-not-appear", "challenge_mode_id": 1 }
            ]
        }
    ]
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOSeedDungeonLaddersTest extends TestCase
{
    use RefreshDatabase;

    public function test_season_dungeon_slugs_reads_static_data_for_the_given_season(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/static-data-expansion-11.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);

        $slugs = app(RaiderIOClient::class)->seasonDungeonSlugs(11, 'season-mn-1');

        $this->assertSame(['maisara-caverns', 'pit-of-saron'], $slugs);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'mythic-plus/static-data')
            && str_contains($request->url(), 'expansion_id=11'));
    }

    public function test_season_dungeon_slugs_returns_empty_for_unknown_season(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/static-data-expansion-11.json')), true);
        Http::fake(['raider.io/*' => Http::response($fixture, 200)]);

        $this->assertSame([], app(RaiderIOClient::class)->seasonDungeonSlugs(11, 'season-xx-9'));
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php`
Expected: FAIL — `Call to undefined method ... seasonDungeonSlugs()`.

- [ ] **Step 4: Implement in `RaiderIOClient`**

Change the `topRuns` signature and query (docblock: note `$dungeon` filters to one dungeon ladder):

```php
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
            // ... rest unchanged
```

Add after `mythicPlusStaticData()`:

```php
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
```

- [ ] **Step 5: Run tests to verify pass**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Style + commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "BE: raiderio client — dungeon-filtered run ladders + season dungeon slugs"
```

---

### Task 3: Seeder — per-dungeon ladder loop, member dedupe, char cap

**Files:**
- Modify: `app/Services/RaiderIO/RaiderIOSeeder.php` (`seedRuns` only)
- Create: `tests/fixtures/raiderio/top-runs-eu-dungeon.json`
- Test: `tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php` (extend)

**Interfaces:**
- Consumes: `topRuns(..., ?string $dungeon)`, `seasonDungeonSlugs()` from Task 2; `SeedOptions::$dungeonPages/$maxCharDispatches`, `SeedReport::$skippedCap` from Task 1.
- Produces: `seedRuns()` behavior — per region: global ladder (`$opts->limit` pages) first, then one ladder per season dungeon (`$opts->dungeonPages` pages each, skipped entirely when `dungeonPages === 0`). A member identity is dispatched at most once per `seedRuns()` invocation. When `maxCharDispatches > 0` and reached, the region's remaining ladders/pages are abandoned (no wasted raider.io calls).

- [ ] **Step 1: Create the dungeon-ladder fixture**

`tests/fixtures/raiderio/top-runs-eu-dungeon.json` — one run; member `Alice/tarren-mill` intentionally duplicates the global fixture (`top-runs-eu.json`), the other four are new:

```json
{
    "rankings": [
        {
            "rank": 1,
            "score": 512.0,
            "run": {
                "keystone_run_id": 2001,
                "roster": [
                    { "character": { "name": "Alice", "realm": { "slug": "tarren-mill" }, "region": { "slug": "eu" } } },
                    { "character": { "name": "Nadia", "realm": { "slug": "kazzak" }, "region": { "slug": "eu" } } },
                    { "character": { "name": "Olaf", "realm": { "slug": "kazzak" }, "region": { "slug": "eu" } } },
                    { "character": { "name": "Pia", "realm": { "slug": "silvermoon" }, "region": { "slug": "eu" } } },
                    { "character": { "name": "Quinn", "realm": { "slug": "silvermoon" }, "region": { "slug": "eu" } } }
                ]
            }
        }
    ]
}
```

- [ ] **Step 2: Write the failing seeder tests**

Add to `RaiderIOSeedDungeonLaddersTest` a fake that routes by URL, plus three tests:

```php
    protected function fakeLadders(int $staticDataStatus = 200): void
    {
        $global = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        $dungeon = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu-dungeon.json')), true);
        $staticData = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/static-data-expansion-11.json')), true);

        Http::fake(function ($request) use ($global, $dungeon, $staticData, $staticDataStatus) {
            $url = $request->url();
            if (str_contains($url, 'static-data')) {
                return Http::response($staticDataStatus === 200 ? $staticData : [], $staticDataStatus);
            }
            if (str_contains($url, 'dungeon=')) {
                return Http::response($dungeon, 200);
            }

            return Http::response($global, 200);
        });
    }

    public function test_dungeon_ladders_seed_new_members_and_dedupe_across_ladders(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->fakeLadders();
        config()->set('raiderio.season', 'season-mn-1');
        config()->set('raiderio.phase.runs_pages_per_dungeon', 1);

        $this->artisan('raiderio:seed', ['--phase' => 'runs', '--limit' => 1, '--regions' => 'eu'])
            ->assertSuccessful();

        // Global ladder: 3 runs × 5 = 15 members. Both dungeon ladders serve the
        // same fixture run (id 2001): first yields 4 new members (Alice deduped),
        // second yields 0 (all five already dispatched this invocation).
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Blizzard\Jobs\SyncCharacterData::class, 19);

        // Run 2001 enters the ledger once despite appearing on both dungeon ladders.
        $this->assertSame(1, \App\Models\SeededRun::where('keystone_run_id', 2001)->count());
    }

    public function test_char_dispatch_cap_stops_the_region(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->fakeLadders();
        config()->set('raiderio.phase.runs_pages_per_dungeon', 1);
        config()->set('raiderio.phase.max_char_dispatches_per_region', 7);

        $this->artisan('raiderio:seed', ['--phase' => 'runs', '--limit' => 1, '--regions' => 'eu'])
            ->assertSuccessful();

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Blizzard\Jobs\SyncCharacterData::class, 7);
    }

    public function test_static_data_failure_skips_dungeon_ladders_but_global_still_seeds(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->fakeLadders(staticDataStatus: 500);
        config()->set('raiderio.phase.runs_pages_per_dungeon', 1);

        $this->artisan('raiderio:seed', ['--phase' => 'runs', '--limit' => 1, '--regions' => 'eu'])
            ->assertSuccessful();

        // Global ladder only — static-data failure must not kill the phase.
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Blizzard\Jobs\SyncCharacterData::class, 15);
    }
```

(Use proper `use` imports at the top of the file instead of FQCNs — shown inline here for unambiguity: `Bus`, `SyncCharacterData`, `SeededRun`.)

- [ ] **Step 3: Run to verify failure**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php`
Expected: the three new tests FAIL (dungeon ladders don't run → 15 dispatches instead of 19; cap ignored → 15 instead of 7).

- [ ] **Step 4: Restructure `RaiderIOSeeder::seedRuns()`**

Replace the method body with:

```php
    public function seedRuns(SeedOptions $opts): SeedReport
    {
        $report = new SeedReport(phase: 'runs', regions: $opts->regions);
        $season = Seasons::raiderioSeasonSlug();

        // Per-dungeon ladders are additive breadth: the global top list is
        // meta-dungeon-biased, per-dungeon lists surface distinct rosters.
        $dungeons = [];
        if ($opts->dungeonPages > 0) {
            try {
                $dungeons = $this->client->seasonDungeonSlugs(
                    (int) config('raiderio.expansion_id'),
                    $season,
                );
            } catch (RaiderIOException $e) {
                $report->errors++;
                Log::warning('raiderio.seed.error', [
                    'phase' => 'runs',
                    'stage' => 'static-data',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('raiderio.seed.start', [
            'phase' => 'runs',
            'regions' => $opts->regions,
            'pages' => $opts->limit,
            'dungeon_pages' => $opts->dungeonPages,
            'dungeons' => $dungeons,
            'season' => $season,
        ]);

        foreach ($opts->regions as $region) {
            // One dispatch per member identity per invocation — the same top
            // players recur across ladders and cap slots must not be wasted.
            $dispatchedMembers = [];
            $regionDispatched = 0;
            $capReached = false;

            // null = the global ladder ($opts->limit pages), then one ladder
            // per dungeon ($opts->dungeonPages pages each).
            foreach ([null, ...$dungeons] as $dungeon) {
                if ($capReached) {
                    break;
                }
                $pages = $dungeon === null ? $opts->limit : $opts->dungeonPages;

                try {
                    foreach ($this->client->topRuns($region, $season, $pages, $dungeon) as $runRef) {
                        $report->considered++;

                        // The ledger is run-level dedupe only (run data is immutable).
                        // Members still go through the TTL gate below, so one missed on
                        // an earlier pass (queue hiccup, prior TTL) is picked up when
                        // the run reappears in the top list.
                        if ($opts->dryRun) {
                            // Dry-run does not mutate the ledger; check existence read-only.
                            if (SeededRun::where('keystone_run_id', $runRef->keystoneRunId)->exists()) {
                                $report->skippedDedupe++;
                            }
                        } else {
                            $inserted = DB::table('seeded_runs')->insertOrIgnore([
                                'keystone_run_id' => $runRef->keystoneRunId,
                                'region' => $region,
                                'seeded_at' => now(),
                            ]);

                            if ($inserted === 0) {
                                $report->skippedDedupe++;
                            }
                        }

                        foreach ($runRef->members as $memberRef) {
                            $memberKey = $memberRef->realmSlug.':'.$memberRef->name;
                            if (isset($dispatchedMembers[$memberKey])) {
                                $report->skippedDedupe++;

                                continue;
                            }

                            if (! $opts->force && $this->characterIsFresh($memberRef)) {
                                $report->skippedTtl++;

                                continue;
                            }

                            if ($opts->maxCharDispatches > 0 && $regionDispatched >= $opts->maxCharDispatches) {
                                $report->skippedCap++;
                                $capReached = true;

                                continue;
                            }

                            $dispatchedMembers[$memberKey] = true;
                            $regionDispatched++;

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

                        if ($capReached) {
                            // Abandon the region's remaining pages/ladders — no point
                            // spending raider.io requests on members we won't dispatch.
                            break;
                        }
                    }
                } catch (RaiderIOThrottledException $e) {
                    // Console context — blocking is fine here, unlike the crawl
                    // jobs where a 429 must release() instead of sleeping a worker.
                    sleep(min($e->retryAfter, 90));

                    continue;
                } catch (RaiderIOException $e) {
                    $report->errors++;
                    Log::warning('raiderio.seed.error', [
                        'phase' => 'runs',
                        'region' => $region,
                        'dungeon' => $dungeon,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('raiderio.seed.complete', $report->toArray());

        return $report;
    }
```

Behavioral notes (already encoded above, listed for the reviewer):
- A throttle/error now skips to the **next ladder**, not the next region (finer-grained than before; same recovery semantics).
- The member dedupe map is per-region and in-memory only — cross-invocation dedupe stays the job-level `ShouldBeUnique` + TTL gate.
- Dry-run counts dispatches against the cap so `--dry-run` predicts real behavior.

- [ ] **Step 5: Run the new tests + existing runs tests**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedDungeonLaddersTest.php tests/Feature/Console/RaiderIOSeedCommandRunsTest.php`
Expected: PASS. (Existing tests unaffected: `runs_pages_per_dungeon` defaults to 0 → `[null]` ladder only, no cap → old behavior. The `skipped_dedupe` counter for repeated members within one invocation is new but no existing test asserts it.)

- [ ] **Step 6: Style + commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "BE: raiderio seeder — per-dungeon run ladders, in-run member dedupe, char dispatch cap"
```

---

### Task 4: Seeder — guild dispatch cap + char-cap test in runs suite

**Files:**
- Modify: `app/Services/RaiderIO/RaiderIOSeeder.php` (`seedGuilds`)
- Test: `tests/Feature/Console/RaiderIOSeedCommandTest.php`

**Interfaces:**
- Consumes: `SeedOptions::$maxGuildDispatches`, `SeedReport::$skippedCap` (Task 1).
- Produces: `seedGuilds()` dispatches at most `maxGuildDispatches` guilds per region per invocation (0 = uncapped); guilds beyond the cap count as `skippedCap` and are naturally retried next night (they'll still be stale).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Console/RaiderIOSeedCommandTest.php` (its `setUp()` already runs `Bus::fake()` and fakes `raider.io/*` with `top-guilds-eu.json`, which contains **3** guilds — see the existing `Bus::assertDispatched(SyncGuildData::class, 3)`):

```php
    public function test_guild_dispatch_cap_limits_dispatches_per_region(): void
    {
        config()->set('raiderio.phase.max_guild_dispatches_per_region', 1);

        $this->artisan('raiderio:seed', [
            '--phase' => 'guilds',
            '--limit' => 3,
            '--regions' => 'eu',
        ])->assertSuccessful();

        // Fixture has 3 stale guilds; cap allows only the first.
        Bus::assertDispatched(SyncGuildData::class, 1);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedCommandTest.php --filter=test_guild_dispatch_cap`
Expected: FAIL — 3 dispatches instead of 1.

- [ ] **Step 3: Implement the cap in `seedGuilds()`**

Inside the region loop, before the `topGuilds` iteration add `$regionDispatched = 0;`. Then between the TTL gate and the dry-run check insert:

```php
                    if ($opts->maxGuildDispatches > 0 && $regionDispatched >= $opts->maxGuildDispatches) {
                        $report->skippedCap++;

                        continue;
                    }
```

and increment `$regionDispatched++;` alongside each `$report->dispatched++` (both the dry-run and real branch). Guilds keep consuming the generator after the cap (unlike runs) — the remaining pages for a 150-guild limit cost ≤8 requests and the `considered`/`skipped_cap` counts stay meaningful.

- [ ] **Step 4: Run guild tests**

Run: `./vendor/bin/phpunit tests/Feature/Console/RaiderIOSeedCommandTest.php`
Expected: PASS (existing + new).

- [ ] **Step 5: Full suite**

Run: `composer test`
Expected: PASS — no regressions anywhere.

- [ ] **Step 6: Style + commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "BE: raiderio seeder — guild dispatch cap"
```

---

### Task 5: Docs

**Files:**
- Modify: `docs/raiderio-seeder.md`

- [ ] **Step 1: Update the seeder doc**

- **Architecture** section: note the runs phase now walks the global ladder plus one ladder per season dungeon (slugs from `/mythic-plus/static-data`, expansion id via `RAIDERIO_EXPANSION_ID`, default 11 — bump at expansion rollover).
- **Phases** section, Phase 2: document `RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON` (0 = off), the in-invocation member dedupe, and that `page > 100` requires `RAIDERIO_ACCESS_KEY`.
- New **Dispatch caps** section: `RAIDERIO_SEED_MAX_GUILD_DISPATCHES_PER_REGION` / `RAIDERIO_SEED_MAX_CHAR_DISPATCHES_PER_REGION` (0 = uncapped), per-region per-invocation, `skipped_cap` report column, capped entries self-heal on later nights via the TTL gate; runs abandon the region at cap, guilds keep counting.
- Update the prod-env line in **Architecture** to the new values (see Task 6).
- Update **Common invocations** if any flag semantics changed (they didn't — note `--limit` still means global-ladder pages for runs).

- [ ] **Step 2: Commit**

```bash
git add docs/raiderio-seeder.md
git commit -m "BE: docs — raiderio seeder dungeon ladders + dispatch caps"
```

---

### Task 6: Deploy + verify (orchestrator/user task — NOT a subagent task)

Requires the deploy-gotchas memory (`guild-service-v2-deploy-gotchas.md` in project memory) — 3 image tags, opcache restart. Env file `/srv/dakis/secrets/guild-service-v2.env` is root-owned: edits go through the user via `! sudo` commands.

- [ ] **Step 1: Append/update env** (user runs, values agreed 2026-07-28):

```
RAIDERIO_SEED_GUILDS_PER_REGION=150
RAIDERIO_SEED_RUNS_PAGES_PER_REGION=100
RAIDERIO_SEED_RUNS_PAGES_PER_DUNGEON=20
RAIDERIO_SEED_MAX_GUILD_DISPATCHES_PER_REGION=25
RAIDERIO_SEED_MAX_CHAR_DISPATCHES_PER_REGION=8000
```

(First two replace existing `=25` lines; last three are new. `RAIDERIO_EXPANSION_ID` not needed — default 11 is correct.)

Expected nightly raider.io cost: global 100×2 + dungeons 8×20×2 + guilds ~8×2 ≈ **540 requests ≈ 36 s** at the 900/min throttle. First-night Blizzard load: ≤8k chars/region + ≤25 guild rosters/region ≈ **~21k jobs**, inside the ~36k/day budget; subsequent nights shrink as the TTL pool fills.

- [ ] **Step 2: Rebuild + restart** per deploy-gotchas memory (build the shared image, retag ×3, `docker compose up -d` the PHP services **only** — avoid recreating the pg container; env-file edits can trigger that, check `docker compose ... up -d --no-deps app horizon scheduler nginx` or whatever the memory prescribes). Then `docker compose restart horizon`.

- [ ] **Step 3: Verify with a dry run**

```bash
docker exec guild-service-v2-app php artisan raiderio:seed --phase=all --dry-run
```

Expected: runs phase `considered` in the thousands (global 2,000 runs + 8 dungeon ladders × 400 runs per region, minus early-abandon if the dry-run cap trips), `dispatched ≤ 8000` per region, `skipped_cap > 0` likely on first run. Guilds phase `considered` 300, `dispatched ≤ 25`/region.

- [ ] **Step 4: Watch the first real night**

Next morning check `storage/logs/laravel-*.log` for `raiderio.seed.complete` and Horizon queue depth (`blizzard-background` should drain within ~14 h). If the queue is still deep by afternoon, halve `RAIDERIO_SEED_MAX_CHAR_DISPATCHES_PER_REGION`.

- [ ] **Step 5: Push subtree split** (per root CLAUDE.md):

```bash
cd /home/dakiman/dev/guild-service-v2
git subtree split --prefix=backend -b be-split && git push be be-split:master && git branch -D be-split
```

---

## Explicitly cut (YAGNI)

- **Heroic/normal raid-rankings seeding** — pool is raid-casual, not "top players by runs". Revisit if the goal broadens.
- **Higher cadence (every 6h)** — user deferred; nightly stays.
- **Extra regions (tw/kr)** — out of scope.
- **`realm` filter on runs** — API doesn't support it (verified 400).
