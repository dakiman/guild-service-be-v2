# Daily raider.io Discovery Seed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the existing raider.io seeder on a daily schedule tuned for maximum new-character discovery, with recursive teammate crawl, weekly re-sync TTL, and a fix so members of already-ledgered runs still get TTL-gated dispatches.

**Architecture:** No new components. One behavioral change in `RaiderIOSeeder::seedRuns` (ledger becomes run-level-only dedupe), one scheduler entry in `bootstrap/app.php`, four prod env values, and a deploy. Spec: `backend/docs/superpowers/specs/2026-07-19-daily-raiderio-discovery-seed-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (SQLite in-memory, `Bus::fake`), Docker deploy on dakis-server-v2.

## Global Constraints

- **No host PHP.** All test runs use the `guild-service-test:latest` docker image from `~/dev/guild-service-v2/backend` with source mounted over the (stale) baked copy. The canonical command (filter varies per step) is:

  ```bash
  cd ~/dev/guild-service-v2/backend && sg docker -c 'docker run --rm \
    -v $PWD/app:/var/www/html/app:ro \
    -v $PWD/tests:/var/www/html/tests:ro \
    -v $PWD/phpunit.xml:/var/www/html/phpunit.xml:ro \
    -v $PWD/bootstrap/app.php:/var/www/html/bootstrap/app.php:ro \
    -v $PWD/database:/var/www/html/database:ro \
    -v $PWD/routes:/var/www/html/routes:ro \
    --entrypoint sh guild-service-test:latest \
    -c "rm -f bootstrap/cache/config.php; ./vendor/bin/phpunit --filter=<FILTER>"'
  ```

  Known pre-existing failures from image staleness (NOT regressions, ignore if they appear in broad runs): `PasswordResetEndpointTest`, `GameDataRealmsEndpointTest`, `CharacterSyncStatusTest`.
- **All `docker` commands via `sg docker -c '...'`** — the shell user is not in the docker group.
- **Commits: NEVER add Claude/Anthropic attribution** — no `Co-Authored-By`, no "Generated with" lines. Commit subjects use the repo's `BE: ...` prefix style. Commit from the monorepo root (`~/dev/guild-service-v2`).
- **Prod deploy** builds all three backend images together (`guild-service-v2-app`, `-horizon`, `-scheduler`) — they share one Dockerfile but have separate tags; building only one leaves the others stale.
- No new migrations, no FE changes.

---

### Task 1: Ledger becomes run-level dedupe only (`RaiderIOSeeder::seedRuns`)

**Files:**
- Modify: `backend/app/Services/RaiderIO/RaiderIOSeeder.php:95-143` (the run loop in `seedRuns`)
- Test: `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php` (1 new test, 2 updated)
- Modify: `backend/docs/raiderio-seeder.md` (Phase 2 dedupe wording)

**Interfaces:**
- Consumes: existing `SeedOptions`, `SeedReport`, `SeededRun`, `characterIsFresh()` — unchanged signatures.
- Produces: new `seedRuns` semantics relied on by the daily schedule (Task 2): a run already in `seeded_runs` increments `skippedDedupe` but its members still go through the TTL/force/dry-run member loop. Report counters: `skippedDedupe` = ledgered runs seen (not members skipped); `skippedTtl`/`dispatched` unchanged in meaning.

- [ ] **Step 1: Write the failing test + update the two tests that pin the old behavior**

In `backend/tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php`, add this new test:

```php
public function test_seed_runs_dispatches_stale_members_of_ledgered_runs_and_skips_fresh_ones(): void
{
    SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);
    Character::factory()->create([
        'name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu',
    ]);
    Character::where(['name' => 'fresh-bob', 'realm' => 'tarren-mill', 'region' => 'eu'])
        ->update(['updated_at' => now()->subMinutes(5)]);

    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topRuns')->andReturn((function () {
        yield new SeedRunRef(1001, 'eu', [
            new SeedCharacterRef('eu', 'tarren-mill', 'fresh-bob'),
            new SeedCharacterRef('eu', 'kazzak', 'Newbie'),
        ]);
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

    // The run is ledgered (dedupe-counted, no re-insert) but its members are
    // still individually gated: fresh-bob is inside the TTL, Newbie has no row.
    Bus::assertDispatched(SyncCharacterData::class, 1);
    Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Newbie');
    Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'fresh-bob');

    $this->assertSame(1, $report->skippedDedupe);
    $this->assertSame(1, $report->skippedTtl);
    $this->assertSame(1, $report->dispatched);
    $this->assertSame(1, SeededRun::count());
}
```

Update `test_seed_runs_skips_already_seeded_runs` (it pins the old skip-everything behavior). Rename and change expectations — members of the ledgered run 1001 now dispatch (Alice has no Character row):

```php
public function test_seed_runs_counts_ledgered_runs_but_still_dispatches_their_members(): void
{
    SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);

    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topRuns')->andReturn((function () {
        yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'Alice')]);
        yield new SeedRunRef(1002, 'eu', [new SeedCharacterRef('eu', 'kazzak', 'Bob')]);
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1));

    Bus::assertDispatched(SyncCharacterData::class, 2);
    Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Alice');
    Bus::assertDispatched(SyncCharacterData::class, fn (SyncCharacterData $j) => $j->name === 'Bob');

    $this->assertSame(2, $report->considered);
    $this->assertSame(2, $report->dispatched);
    $this->assertSame(1, $report->skippedDedupe);
    $this->assertSame(1, SeededRun::where('keystone_run_id', 1001)->count()); // no duplicate ledger row
}
```

Update `test_seed_runs_dry_run_skips_already_seeded_runs_without_writing` — dry-run mirrors the new semantics (member A of ledgered run 1001 is now counted as would-dispatch):

```php
public function test_seed_runs_dry_run_counts_ledgered_runs_without_writing(): void
{
    SeededRun::create(['keystone_run_id' => 1001, 'region' => 'eu']);

    $client = $this->mock(RaiderIOClient::class);
    $client->shouldReceive('topRuns')->andReturn((function () {
        yield new SeedRunRef(1001, 'eu', [new SeedCharacterRef('eu', 'tarren-mill', 'A')]);
        yield new SeedRunRef(1002, 'eu', [new SeedCharacterRef('eu', 'kazzak', 'B')]);
    })());

    $seeder = app(RaiderIOSeeder::class);
    $report = $seeder->seedRuns(new SeedOptions(regions: ['eu'], limit: 1, dryRun: true));

    Bus::assertNothingDispatched();
    $this->assertSame(1, $report->skippedDedupe);
    $this->assertSame(2, $report->dispatched); // both members counted; neither is fresh
    // Dry-run never mutates the ledger.
    $this->assertFalse(SeededRun::where('keystone_run_id', 1002)->exists());
    $this->assertSame(1, SeededRun::count());
}
```

All other tests in the file stay untouched.

- [ ] **Step 2: Run the file to verify the expected failures**

Run (canonical docker test command with): `--filter=RaiderIOSeederRunsTest`
Expected: FAIL — the new test and both updated tests fail (0 dispatches where 1–2 expected); the remaining tests pass.

- [ ] **Step 3: Implement the change**

In `backend/app/Services/RaiderIO/RaiderIOSeeder.php`, replace the run loop body inside `seedRuns` (currently lines 97–143). The `continue` statements in the two dedupe branches are removed so control always reaches the member loop; everything else is identical:

```php
                foreach ($this->client->topRuns($region, $season, $opts->limit) as $runRef) {
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
```

- [ ] **Step 4: Run the unit file, then the feature file, to verify green**

Run (canonical docker test command with): `--filter=RaiderIOSeederRunsTest`
Expected: PASS (all tests).
Run (canonical docker test command with): `--filter=RaiderIOSeedCommandRunsTest`
Expected: PASS (feature tests have no pre-ledgered scenario; confirms no collateral).

- [ ] **Step 5: Update the seeder doc**

In `backend/docs/raiderio-seeder.md`, in the Phases section, replace the Phase 2 sentence

`Dedupe via `seeded_runs` table on `keystone_run_id` (immutable).`

with:

`Run-level dedupe via `seeded_runs` on `keystone_run_id` (immutable); since 2026-07-19 the ledger no longer skips members — every member of every listed run goes through the per-character TTL gate (`RAIDERIO_SEED_CHAR_TTL`), so stale/missed members of known runs are picked up on later passes.`

- [ ] **Step 6: Commit**

```bash
cd ~/dev/guild-service-v2 && git add backend/app/Services/RaiderIO/RaiderIOSeeder.php backend/tests/Unit/Services/RaiderIO/RaiderIOSeederRunsTest.php backend/docs/raiderio-seeder.md && git commit -m 'BE: raiderio seeder — ledger dedupes runs only, members always TTL-gated'
```

---

### Task 2: Daily schedule entry + registration test

**Files:**
- Modify: `backend/bootstrap/app.php:34-37` (insert after the `raiderio:crawl-runs` entry)
- Create: `backend/tests/Feature/Console/ScheduleRegistrationTest.php`
- Modify: `backend/docs/raiderio-seeder.md` (add scheduling note)
- Modify: `backend/CLAUDE.md` (one-line seeder mention gains the cadence)

**Interfaces:**
- Consumes: `raiderio:seed --phase=all` (existing command; `--phase` is required — empty/missing errors; `all` runs guilds then runs with config defaults).
- Produces: schedule expression `0 1 * * *` for `raiderio:seed --phase=all`, asserted by the new test.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Console/ScheduleRegistrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleRegistrationTest extends TestCase
{
    public function test_raiderio_seed_all_phases_is_scheduled_daily_at_0100(): void
    {
        $events = collect(app(Schedule::class)->events());

        $event = $events->first(
            fn ($e) => str_contains((string) $e->command, 'raiderio:seed')
                && str_contains((string) $e->command, '--phase=all')
        );

        $this->assertNotNull($event, 'raiderio:seed --phase=all is not scheduled');
        $this->assertSame('0 1 * * *', $event->expression);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (canonical docker test command with): `--filter=ScheduleRegistrationTest`
Expected: FAIL with `raiderio:seed --phase=all is not scheduled`.

- [ ] **Step 3: Add the schedule entry**

In `backend/bootstrap/app.php`, directly after the existing `raiderio:crawl-runs` block (lines 34–37), add:

```php
        $schedule->command('raiderio:seed --phase=all')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 4: Run test to verify it passes**

Run (canonical docker test command with): `--filter=ScheduleRegistrationTest`
Expected: PASS.

- [ ] **Step 5: Update docs**

`backend/docs/raiderio-seeder.md` — add under the Architecture section:

`**Scheduled daily 01:00 UTC** (`bootstrap/app.php`): `raiderio:seed --phase=all`. Discovery breadth and freshness come from prod env: `RAIDERIO_SEED_GUILDS_PER_REGION=25`, `RAIDERIO_SEED_RUNS_PAGES_PER_REGION=25`, `RAIDERIO_SEED_CHAR_TTL=604800` (weekly re-sync), `RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED=true`.`

`backend/CLAUDE.md` — in the Sync orchestration section, extend the RaiderIO seeder line:

`RaiderIO seeder (`app/Services/RaiderIO/`) — lean discovery layer bootstrapping from raider.io top-lists, **scheduled daily 01:00 UTC** (`raiderio:seed --phase=all`; breadth/TTL tuned via env). Detail: `docs/raiderio-seeder.md`.`

- [ ] **Step 6: Commit**

```bash
cd ~/dev/guild-service-v2 && git add backend/bootstrap/app.php backend/tests/Feature/Console/ScheduleRegistrationTest.php backend/docs/raiderio-seeder.md backend/CLAUDE.md && git commit -m 'BE: schedule raiderio:seed --phase=all daily 01:00 UTC'
```

---

### Task 3: Prod env + deploy + verification

No TDD here — this is ops. Every step has a verify command; stop and report if any verification fails.

**Files:**
- Modify: `/srv/dakis/secrets/guild-service-v2.env` (outside the repo; gitignored secrets)

**Interfaces:**
- Consumes: images/services `guild-service-v2-{app,horizon,scheduler}` in `/srv/dakis`; env keys read by `config/raiderio.php`.
- Produces: a live daily schedule; first run tonight 01:00 UTC.

- [ ] **Step 1: Add the four env keys**

Append to `/srv/dakis/secrets/guild-service-v2.env` (keys must not already exist — check first with `rg -n 'RAIDERIO_SEED' /srv/dakis/secrets/guild-service-v2.env`; the file currently has only `RAIDERIO_ACCESS_KEY` and `RAIDERIO_RATE_PER_MINUTE`):

```
RAIDERIO_SEED_CHAR_TTL=604800
RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED=true
RAIDERIO_SEED_GUILDS_PER_REGION=25
RAIDERIO_SEED_RUNS_PAGES_PER_REGION=25
```

- [ ] **Step 2: Rebuild and recreate all three backend services**

Env-file changes need a container recreate, and code changes need an image rebuild — all three tags together:

```bash
cd /srv/dakis && sg docker -c 'docker compose build guild-service-v2-app guild-service-v2-horizon guild-service-v2-scheduler && docker compose up -d guild-service-v2-app guild-service-v2-horizon guild-service-v2-scheduler'
```

Expected: three images rebuilt, three containers recreated, exit 0.

- [ ] **Step 3: Verify config + schedule inside the containers**

```bash
sg docker -c 'docker exec guild-service-v2-app php artisan config:show raiderio'
```
Expected: `character_resync_ttl: 604800`, `teammate_crawl_during_seed: true`, `phase.guilds_per_region: 25`, `phase.runs_pages_per_region: 25`.

```bash
sg docker -c 'docker exec guild-service-v2-scheduler php artisan schedule:list' | grep raiderio
```
Expected: two lines — `raiderio:crawl-runs` (02:00, dormant) and `raiderio:seed --phase=all` at `0 1 * * *`.

- [ ] **Step 4: Dry-run smoke test against live raider.io**

```bash
sg docker -c 'docker exec guild-service-v2-app php artisan raiderio:seed --phase=all --dry-run'
```

Expected: exit 0 and a report table with two rows (guilds, runs); `considered` ≈ 50 guilds and ≈ 1000 runs across eu+us; `dispatched` reflects would-sync counts; `errors` 0. This costs ~55 raider.io requests — well inside the keyed 900/min throttle.

- [ ] **Step 5: Sanity-check the API is still healthy**

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://192.168.100.81:8091/api/v1/game-data/seasons
```
Expected: `200`.

- [ ] **Step 6: Push to GitHub (subtree split)**

```bash
cd ~/dev/guild-service-v2 && git subtree split --prefix=backend -b be-split && git push be be-split:master && git branch -D be-split
```

Expected: fast-forward push to `dakiman/guild-service-be-v2`.

- [ ] **Step 7: Report first-night expectations**

Nothing to run — note in the final report: first scheduled run is tonight 01:00 UTC; expect 6–9k Full syncs draining ~4–6h on `blizzard-background`/`blizzard-roster-sync` (Horizon dashboard will show the burst); steady state much smaller. Suggest a next-day check of `raiderio.seed.complete` log lines (`storage/logs`) and character-count growth.
