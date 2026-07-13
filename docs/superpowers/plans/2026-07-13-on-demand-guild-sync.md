# On-Demand Guild Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make guild syncs on-demand only — no background sweeps, no roster fan-out amplification — per the approved spec at `backend/docs/superpowers/specs/2026-07-13-on-demand-guild-sync-design.md`.

**Architecture:** Trim the existing jobs (Approach A): `SyncGuildData` gains a `SyncOrigin` param for lane routing and stops dispatching `SyncGuildRoster` unless explicitly asked (`forceRosterFanout`, seeder-only). `ProactiveSyncGuilds` is deleted. `retryUntil` goes 6h → 24h on the three Blizzard sync jobs. A one-off `guilds:backfill-shells` command populates never-synced guild shells. Logging becomes deployment-side env config (no code change needed — `config/logging.php` already reads `LOG_STACK`).

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (SQLite in-memory, `queue=sync`), Horizon/Redis queues.

## Global Constraints

- All work happens in `backend/` of the `guild-service-v2` repo.
- Tests: `./vendor/bin/phpunit <path>` for single files; `composer test` for the full suite (clears config cache first). Run from `backend/`.
- Style: `./vendor/bin/pint` on touched files before each commit.
- Job constructor params that must survive unserialize of old-shape queued payloads are **non-readonly with a property-style default** (see `SyncCharacterData::$origin` comment for rationale). Never infer queue lanes from other params — lanes come from `SyncOrigin` only.
- Every Blizzard job keeps: `ShouldBeUnique` (60s), `middleware()` = `[BlizzardHealthCheck, BlizzardRateLimiter]` in that order, `failed()` logging.
- Commit after each task with a one-line message; do not push.

---

### Task 1: Add `SyncOrigin::Discovery`

**Files:**
- Modify: `app/Enums/SyncOrigin.php`
- Test: `tests/Unit/Enums/SyncOriginTest.php`

**Interfaces:**
- Produces: `SyncOrigin::Discovery` case (`value: 'discovery'`), `->queue()` returning `'blizzard-background'`. Tasks 2 and 5 dispatch with it.

- [ ] **Step 1: Write the failing test**

Append to the existing class in `tests/Unit/Enums/SyncOriginTest.php` (keep all existing tests):

```php
public function test_discovery_routes_to_background_queue(): void
{
    $this->assertSame('blizzard-background', SyncOrigin::Discovery->queue());
}

public function test_discovery_value_is_stable(): void
{
    // Serialized into queued payloads and Horizon tags — never change it.
    $this->assertSame('discovery', SyncOrigin::Discovery->value);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Enums/SyncOriginTest.php`
Expected: FAIL — `undefined constant App\Enums\SyncOrigin::Discovery`

- [ ] **Step 3: Implement**

In `app/Enums/SyncOrigin.php`, add the case and extend the match arm:

```php
enum SyncOrigin: string
{
    case UserLookup = 'user-lookup';
    case RosterFanout = 'roster-fanout';
    case TeammateCrawl = 'teammate-crawl';
    case Proactive = 'proactive';
    // Guild discovered as a side effect of a character sync (shell row
    // creation) or shell backfill — background lane, never the user lane.
    case Discovery = 'discovery';

    public function queue(): string
    {
        return match ($this) {
            self::UserLookup => 'blizzard-user-sync',
            self::RosterFanout => 'blizzard-roster-sync',
            self::TeammateCrawl, self::Proactive, self::Discovery => 'blizzard-background',
        };
    }
}
```

Also update the enum's docblock first line from "a SyncCharacterData job" to "a Blizzard sync job (character or guild)".

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Enums/SyncOriginTest.php`
Expected: PASS (all methods, old and new)

- [ ] **Step 5: Commit**

```bash
git add app/Enums/SyncOrigin.php tests/Unit/Enums/SyncOriginTest.php
git commit -m "Add SyncOrigin::Discovery case routing to blizzard-background"
```

---

### Task 2: `SyncGuildData` — origin lane, opt-in roster fan-out, drop `forceCascade`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncGuildData.php` (constructor lines 38-53, `uniqueId` 55-65, `handle` tail 207-213)
- Modify: `app/Http/Controllers/GuildController.php:38`
- Modify: `app/Services/GuildService.php:35`
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php:316` (auto-discover dispatch)
- Modify: `app/Services/RaiderIO/RaiderIOSeeder.php:50-55` (+ add `use App\Enums\SyncOrigin;` import)
- Delete: `tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`
- Create: `tests/Feature/Blizzard/Jobs/SyncGuildDataRosterDispatchTest.php`

**Interfaces:**
- Consumes: `SyncOrigin::Discovery` from Task 1.
- Produces: new constructor signature
  `new SyncGuildData(string $region, string $realm, string $name, bool $forceRosterFanout = false, SyncOrigin $origin = SyncOrigin::UserLookup)`.
  `forceCascade` no longer exists. Task 5's command dispatches with `origin: SyncOrigin::Discovery`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Blizzard/Jobs/SyncGuildDataRosterDispatchTest.php` (replaces `SyncGuildDataForceCascadeTest.php` — same fixtures, new behavior):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Enums\SyncOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SyncGuildDataRosterDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_defaults_to_user_lookup_origin_on_user_sync_lane(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');

        $this->assertSame(SyncOrigin::UserLookup, $job->origin);
        $this->assertSame('blizzard-user-sync', $job->queue);
    }

    public function test_discovery_origin_routes_to_background_lane(): void
    {
        $job = new SyncGuildData(
            region: 'eu', realm: 'tarren-mill', name: 'echo',
            origin: SyncOrigin::Discovery,
        );

        $this->assertSame('blizzard-background', $job->queue);
    }

    public function test_tags_carry_origin_and_guild_identity(): void
    {
        $job = new SyncGuildData(
            region: 'eu', realm: 'tarren-mill', name: 'echo',
            origin: SyncOrigin::Discovery,
        );

        $this->assertSame(['origin:discovery', 'guild:eu:tarren-mill:echo'], $job->tags());
    }

    public function test_does_not_dispatch_roster_job_by_default(): void
    {
        // The 2026-07-12 incident: every guild sync fanning out roster work
        // flooded the queues. Default is now profile + roster rows ONLY.
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertNotDispatched(SyncGuildRoster::class);
    }

    public function test_dispatches_roster_job_with_force_fanout_when_asked(): void
    {
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo', forceRosterFanout: true))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === true);
    }

    public function test_unique_id_distinguishes_force_mode_from_auto_mode(): void
    {
        $auto = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $force = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo', forceRosterFanout: true);

        $this->assertNotSame($auto->uniqueId(), $force->uniqueId());
    }

    public function test_unique_id_matches_for_same_mode(): void
    {
        $a = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $b = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');

        $this->assertSame($a->uniqueId(), $b->uniqueId());
    }

    private function fakeBlizzardGuildEndpoints(): void
    {
        // Important: more-specific roster pattern must come before the looser
        // guild-profile pattern (Laravel's Http::fake matches in order).
        Http::fake([
            '*/data/wow/guild/*/echo/roster*' => Http::response(['members' => []]),
            '*/data/wow/guild/*/echo*' => Http::response([
                'name' => 'Echo',
                'faction' => ['type' => 'HORDE'],
                'achievement_points' => 0,
                'member_count' => 0,
                'created_timestamp' => 0,
                'realm' => ['name' => 'Tarren Mill', 'slug' => 'tarren-mill'],
            ]),
        ]);
    }
}
```

Delete the old test file:

```bash
git rm tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataRosterDispatchTest.php`
Expected: FAIL — `Unknown named parameter $origin` / missing `$origin` property.

- [ ] **Step 3: Implement `SyncGuildData` changes**

In `app/Blizzard/Jobs/SyncGuildData.php` add `use App\Enums\SyncOrigin;` and replace the constructor, `uniqueId()`, and the `handle()` tail:

```php
    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        // Non-readonly with property-default so unserialize of old-shape queued
        // jobs gets `false` rather than "uninitialized" — see SyncCharacterData
        // forceTeammateCrawl for the same pattern + rationale. True only for the
        // raider.io seeder: it opts in to the SyncGuildRoster fan-out.
        public bool $forceRosterFanout = false,
        // Origin decides the queue lane — never infer routing from other params
        // (see SyncOrigin docblock; 2026-07-06 + 2026-07-12 incidents). Old-shape
        // payloads rehydrate as UserLookup, which is harmless: their queue was
        // already fixed at dispatch time.
        public SyncOrigin $origin = SyncOrigin::UserLookup,
    ) {
        $this->onQueue($origin->queue());
    }

    public function uniqueId(): string
    {
        // Mode segment so a queued auto-mode job (visit / auto-discover)
        // doesn't dedupe a force-mode job (seeder), which would silently skip
        // the per-member fan-out. Two parallel jobs for the same guild may run
        // during a collision; both honor the rate limiter and the cost is one
        // redundant API round-trip.
        $mode = $this->forceRosterFanout ? 'force' : 'auto';

        return "sync-guild:{$this->region}:{$this->realm}:{$this->name}:{$mode}";
    }

    /**
     * Horizon tags: make queue floods attributable to their origin in the
     * dashboard — mirrors SyncCharacterData::tags().
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            "origin:{$this->origin->value}",
            "guild:{$this->region}:{$this->realm}:{$this->name}",
        ];
    }
```

`handle()` tail (replaces the unconditional dispatch at former lines 207-212):

```php
        // Roster fan-out is opt-in (raider.io seeder only). User visits and
        // auto-discover stop at profile + roster rows: members become full
        // characters when individually viewed. The unconditional dispatch here
        // is what amplified the 2026-07-12 queue flood — see
        // docs/superpowers/specs/2026-07-13-on-demand-guild-sync-design.md.
        if ($this->forceRosterFanout) {
            SyncGuildRoster::dispatch($guild, true);
        }
```

- [ ] **Step 4: Update the four dispatch sites**

`app/Http/Controllers/GuildController.php:38`:

```php
            SyncGuildData::dispatch($region, $realm, $guild);
```

`app/Services/GuildService.php:35`:

```php
            SyncGuildData::dispatch($region, $realm, $name);
```

`app/Blizzard/Jobs/SyncCharacterData.php:316` (auto-discover; `SyncOrigin` is already imported there). Also update the comment block above it (lines 309-314) to say the dispatch is profile + roster rows on the background lane, no fan-out:

```php
            if ($guild->wasRecentlyCreated) {
                SyncGuildData::dispatch(
                    $this->region,
                    $profile->guildRealm,
                    $guildName,
                    origin: SyncOrigin::Discovery,
                );
            }
```

`app/Services/RaiderIO/RaiderIOSeeder.php:50` (add `use App\Enums\SyncOrigin;` to imports):

```php
                    SyncGuildData::dispatch(
                        $ref->region,
                        $ref->realmSlug,
                        $ref->name,
                        forceRosterFanout: true,
                        origin: SyncOrigin::Discovery,
                    );
```

- [ ] **Step 5: Run the new test + every suite touching these files**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataRosterDispatchTest.php \
  tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php \
  tests/Feature/Blizzard/Jobs/SyncGuildDataStalePruneTest.php \
  tests/Feature/Blizzard/Jobs/SyncCharacterDataGuildLeaveTest.php \
  tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php \
  tests/Unit/Services/RaiderIO/RaiderIOSeederTest.php \
  tests/Feature/Console/RaiderIOSeedCommandTest.php \
  tests/Feature/GuildShowEndpointTest.php \
  tests/Feature/Http/GuildControllerEagerLoadTest.php \
  tests/Feature/Http/GuildControllerPaginationTest.php
```
Expected: PASS. If a test still references `forceCascade`, it must be updated to the new signature (behavior expectations come from the spec: visits do NOT fan out).

- [ ] **Step 6: Full suite + style, then commit**

Run: `composer test` — expected: green.
Run: `./vendor/bin/pint app/Blizzard/Jobs/SyncGuildData.php app/Http/Controllers/GuildController.php app/Services/GuildService.php app/Blizzard/Jobs/SyncCharacterData.php app/Services/RaiderIO/RaiderIOSeeder.php tests/Feature/Blizzard/Jobs/SyncGuildDataRosterDispatchTest.php`

```bash
git add -A
git commit -m "Make SyncGuildData lane-aware and cascade-free (roster fan-out seeder-only)"
```

---

### Task 3: Delete `ProactiveSyncGuilds`

**Files:**
- Delete: `app/Blizzard/Jobs/ProactiveSyncGuilds.php`
- Modify: `bootstrap/app.php` (remove import line 6 and the schedule line 29)

**Interfaces:**
- Consumes: nothing. Produces: nothing — the class has no other references (`rg -l ProactiveSyncGuilds` returns only the class itself and `bootstrap/app.php`).

- [ ] **Step 1: Remove class and schedule entry**

```bash
git rm app/Blizzard/Jobs/ProactiveSyncGuilds.php
```

In `bootstrap/app.php` delete these two lines:

```php
use App\Blizzard\Jobs\ProactiveSyncGuilds;
```

```php
        $schedule->job(new ProactiveSyncGuilds)->weeklyOn(0, '04:00')->withoutOverlapping();
```

- [ ] **Step 2: Verify nothing references it and the schedule loads**

Run: `rg -l "ProactiveSyncGuilds" .` — expected: only docs/spec/plan files (no PHP).
Run: `docker compose exec app php artisan schedule:list` (or `php artisan schedule:list` if running locally) — expected: no `ProactiveSyncGuilds` entry, command exits 0 (proves `bootstrap/app.php` still parses).

- [ ] **Step 3: Run full suite**

Run: `composer test`
Expected: PASS (no test referenced the class).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Delete ProactiveSyncGuilds weekly sweep (2026-07-12 queue-starvation incident)"
```

---

### Task 4: `retryUntil` 6h → 24h on the three sync jobs

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php:69-72`
- Modify: `app/Blizzard/Jobs/SyncGuildData.php` (`retryUntil()`)
- Modify: `app/Blizzard/Jobs/SyncGuildRoster.php:59-62`
- Test: `tests/Unit/Blizzard/Jobs/JobRetryMiddlewareContractTest.php`

**Interfaces:**
- Consumes/Produces: none beyond the changed return values. `SyncUserCharacters` intentionally stays at its current window (out of spec scope).

- [ ] **Step 1: Update the contract test to pin the new window**

In `tests/Unit/Blizzard/Jobs/JobRetryMiddlewareContractTest.php`, change the two guild-job assertions from `addHours(5)` to `addHours(23)` and add a `SyncCharacterData` assertion (it was never covered):

```php
    public function test_sync_character_data_retries_are_time_bounded(): void
    {
        $this->assertGreaterThan(now()->addHours(23), (new SyncCharacterData('eu', 'tarren-mill', 'x'))->retryUntil());
    }

    public function test_sync_guild_data_retries_are_time_bounded(): void
    {
        $this->assertGreaterThan(now()->addHours(23), (new SyncGuildData('eu', 'tarren-mill', 'echo'))->retryUntil());
    }

    public function test_sync_guild_roster_retries_are_time_bounded(): void
    {
        $this->assertGreaterThan(now()->addHours(23), (new SyncGuildRoster(Guild::factory()->create()))->retryUntil());
    }
```

Leave `test_sync_user_characters_retries_are_time_bounded` (`addHours(5)`) untouched.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/JobRetryMiddlewareContractTest.php`
Expected: FAIL — the three assertions (6h is not greater than now+23h).

- [ ] **Step 3: Implement**

In each of `SyncCharacterData`, `SyncGuildData`, `SyncGuildRoster`, change:

```php
    // Time-bound retries: every middleware release() re-queues without burning
    // a fixed $tries budget; only real exceptions (maxExceptions) cap the work.
    // 24h window (was 6h): with background sweeps gone, expiry means the queue
    // was genuinely wedged for a day — reaping is then the correct outcome.
    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/JobRetryMiddlewareContractTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php app/Blizzard/Jobs/SyncGuildData.php app/Blizzard/Jobs/SyncGuildRoster.php tests/Unit/Blizzard/Jobs/JobRetryMiddlewareContractTest.php
git commit -m "Bump sync job retryUntil 6h -> 24h"
```

---

### Task 5: `guilds:backfill-shells` command

**Files:**
- Create: `app/Console/Commands/BackfillGuildShells.php`
- Test: `tests/Feature/Console/BackfillGuildShellsCommandTest.php`

**Interfaces:**
- Consumes: `SyncGuildData` signature from Task 2, `SyncOrigin::Discovery` from Task 1.
- Produces: `php artisan guilds:backfill-shells [--limit=0] [--dry-run]` (`--limit=0` = no cap). One-off, run manually after deploy. Idempotent: successful syncs set `roster_synced_at`, dropping the guild from the target set; guilds that 404 stay null and would be re-dispatched on a repeat run (harmless — the not-found cache short-circuits within 24h).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/BackfillGuildShellsCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncOrigin;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillGuildShellsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_discovery_sync_for_never_synced_shells_only(): void
    {
        Queue::fake();
        $shell = Guild::factory()->create(['roster_synced_at' => null]);
        Guild::factory()->create(['roster_synced_at' => now()]);

        $this->artisan('guilds:backfill-shells')
            ->expectsOutputToContain('Dispatched 1')
            ->assertExitCode(0);

        Queue::assertPushed(SyncGuildData::class, 1);
        Queue::assertPushed(SyncGuildData::class, fn (SyncGuildData $job) => $job->name === $shell->name
            && $job->origin === SyncOrigin::Discovery
            && $job->forceRosterFanout === false);
    }

    public function test_limit_caps_dispatches(): void
    {
        Queue::fake();
        Guild::factory()->count(3)->create(['roster_synced_at' => null]);

        $this->artisan('guilds:backfill-shells', ['--limit' => 2])->assertExitCode(0);

        Queue::assertPushed(SyncGuildData::class, 2);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Queue::fake();
        Guild::factory()->count(2)->create(['roster_synced_at' => null]);

        $this->artisan('guilds:backfill-shells', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] would dispatch 2')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Console/BackfillGuildShellsCommandTest.php`
Expected: FAIL — command `guilds:backfill-shells` does not exist.

- [ ] **Step 3: Implement**

Create `app/Console/Commands/BackfillGuildShells.php` (mirrors `BackfillSlices` style; commands are auto-discovered in this directory):

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncOrigin;
use App\Models\Guild;
use Illuminate\Console\Command;

class BackfillGuildShells extends Command
{
    protected $signature = 'guilds:backfill-shells {--limit=0 : Max dispatches, 0 = all} {--dry-run}';

    protected $description = 'One-off: dispatch a Discovery-lane SyncGuildData for guild shells that have never been synced';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $q = Guild::query()
            ->whereNull('roster_synced_at')
            ->orderBy('id');

        // count() ignores limit() (the LIMIT applies to the one-row aggregate),
        // so cap the reported number manually.
        $total = $q->count();
        if ($limit > 0) {
            $total = min($total, $limit);
            $q->limit($limit);
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] would dispatch {$total} guild syncs.");

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($q->cursor() as $guild) {
            SyncGuildData::dispatch(
                $guild->region,
                $guild->realm,
                $guild->name,
                origin: SyncOrigin::Discovery,
            );
            $n++;
        }

        $this->info("Dispatched {$n} guild syncs onto blizzard-background.");

        return self::SUCCESS;
    }
}
```


- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/Console/BackfillGuildShellsCommandTest.php`
Expected: PASS

- [ ] **Step 5: Full suite + style, then commit**

Run: `composer test` — expected: green.
Run: `./vendor/bin/pint app/Console/Commands/BackfillGuildShells.php tests/Feature/Console/BackfillGuildShellsCommandTest.php`

```bash
git add app/Console/Commands/BackfillGuildShells.php tests/Feature/Console/BackfillGuildShellsCommandTest.php
git commit -m "Add guilds:backfill-shells one-off command"
```

---

### Task 6: Update `backend/CLAUDE.md`

**Files:**
- Modify: `backend/CLAUDE.md`

**Interfaces:** none — documentation only, but required so future sessions don't re-learn the incident.

- [ ] **Step 1: Apply the edits**

1. **Request flow → Background line:** change
   `Background: Scheduler → ProactiveSyncCharacters/Guilds → fan out per-entity SyncCharacterData / SyncGuildData.`
   to
   `Background: Scheduler → ProactiveSyncCharacters → fan out per-entity SyncCharacterData. Guild syncs are on-demand only (visit, auto-discover, seeder) — the weekly ProactiveSyncGuilds sweep was deleted after the 2026-07-12 queue-starvation incident.`
2. **Jobs/ paragraph:** change `(retryUntil() 6h + $maxExceptions = 3, ...)` to `(retryUntil() 24h + $maxExceptions = 3, ...)` and the trailing `churn a job past its 6h window` to `24h window`.
3. **Sync orchestration → Auto-discover guild bullet:** replace with:
   `**Auto-discover guild.** When SyncCharacterData writes a Guild::firstOrCreate shell, it dispatches SyncGuildData (origin: Discovery → blizzard-background) if wasRecentlyCreated — profile + roster rows only, no member fan-out. One-off shell backfill: php artisan guilds:backfill-shells --dry-run.`
4. **Queue priority section:** after the `SyncCharacterData` routing sentence, add:
   `SyncGuildData routes the same way (origin param: UserLookup → user-sync; Discovery → background). Its SyncGuildRoster fan-out is opt-in via forceRosterFanout — only the raider.io seeder passes it.`

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "Update CLAUDE.md for on-demand guild sync"
```

---

### Task 7: Deploy + logging env (manual, on dakis-server-v2)

**Files (outside this repo, on the server):**
- Modify: `/srv/dakis/secrets/guild-service-v2.env` (or wherever the BE env lives — check `apps/guild-service-v2/compose.yml` `env_file:`)
- Modify: `/srv/dakis/apps/guild-service-v2/compose.yml`

**Interfaces:** consumes stock Laravel `config/logging.php` env hooks: `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS` — no code change was needed.

- [ ] **Step 1: Logging env + bind mount**

Add to the backend env file:

```
LOG_CHANNEL=stack
LOG_STACK=stderr,daily
```

(`LOG_DAILY_DAYS` default is already 14.)

In `compose.yml`, add to **each** of the three PHP services (`guild-service-v2-app`, `guild-service-v2-horizon`, `guild-service-v2-scheduler`):

```yaml
    volumes:
      - /srv/dakis/data/guild-service-v2/logs:/var/www/html/storage/logs
```

Create the host dir writable by the container's `www-data` (uid 33):

```bash
mkdir -p /srv/dakis/data/guild-service-v2/logs
sudo chown 33:33 /srv/dakis/data/guild-service-v2/logs   # user runs via `! sudo ...`
```

- [ ] **Step 2: Rebuild + redeploy**

Per the existing deploy flow (3 image tags — app, horizon, scheduler share the BE image; see deploy-gotchas memory):

```bash
cd /srv/dakis
sg docker -c 'docker compose build guild-service-v2-app'
sg docker -c 'docker compose up -d guild-service-v2-app guild-service-v2-horizon guild-service-v2-scheduler'
```

- [ ] **Step 3: Verify on the server**

```bash
# Lane + schedule sanity
sg docker -c 'docker exec guild-service-v2-app php artisan schedule:list' | grep -ci proactivesyncguilds   # expected: 0
# Logging: trigger any request, then
sg docker -c 'docker logs --since 5m guild-service-v2-horizon' | tail   # app log lines now visible
ls /srv/dakis/data/guild-service-v2/logs/                               # laravel-YYYY-MM-DD.log appears
```

- [ ] **Step 4: Optional backfill + commit deployment repo**

```bash
sg docker -c 'docker exec guild-service-v2-app php artisan guilds:backfill-shells --dry-run'   # expect ~11.7k
# if happy:
sg docker -c 'docker exec guild-service-v2-app php artisan guilds:backfill-shells'
cd /srv/dakis && git add -A && git -c user.email=dakiman@dakis-server-v2 -c user.name=dakiman commit -m 'guild-service-v2: persist BE logs (stderr+daily bind mount)'
```

---

## Verification (post-deploy, over the following week)

- Horizon failed-jobs count stays flat (old rows age out via `queue:prune-failed --hours=168` by 2026-07-20).
- Next Sunday 04:00: no `SyncGuildData` flood (check `failed_jobs` Monday morning: `select count(*) from failed_jobs where failed_at > now() - interval '1 day'` — expected ≈ 0).
- Guild page visit on a stale guild still returns 202/stale-header and populates within a minute.
