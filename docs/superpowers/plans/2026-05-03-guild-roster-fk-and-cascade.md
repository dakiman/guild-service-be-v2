# Guild roster FK wiring + user-visit cascade — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Populate `guild_members.character_id` on both write paths (roster→character and character→roster), drop the GuildController stitch workaround, and make a user-visit to a guild force-cascade Full per-member sync + M+ teammate crawl with TTL gates.

**Architecture:** Forward-only. No backfill command, no migration. Three Blizzard jobs grow one constructor param each (`forceCascade`/`forceFanout`/`forceTeammateCrawl` already exist on the lower two — we add `forceCascade` on `SyncGuildData` and thread it down). One controller and one service get a single `forceCascade: true` argument added. `SyncGuildRoster` gains a unified TTL gate covering both Shallow and Full dispatches. Two env defaults bump.

**Tech Stack:** Laravel 13 / PHP 8.4 / Postgres / Eloquent / Bus::fake-driven tests.

**Spec:** `backend/docs/superpowers/specs/2026-05-03-guild-roster-fk-and-cascade-design.md`.

---

## File Structure

**Modify (production code):**
- `backend/app/Blizzard/Jobs/SyncGuildData.php` — add `forceCascade` param; pre-resolve `character_id` for `GuildMember::upsert`; pass `forceFanout` to `SyncGuildRoster::dispatch`.
- `backend/app/Blizzard/Jobs/SyncGuildRoster.php` — unify TTL gate covering Shallow + Full; pass `forceTeammateCrawl: true` when force-cascading.
- `backend/app/Blizzard/Jobs/SyncCharacterData.php` — backfill `GuildMember.character_id` immediately after `Character::updateOrCreate`.
- `backend/app/Http/Controllers/GuildController.php` — remove stitch block (lines 54-86) and `Character` import (line 13); pass `forceCascade: true` on the missing-guild dispatch.
- `backend/app/Services/GuildService.php` — pass `forceCascade: true` on the stale-guild dispatch.
- `backend/.env.example` — bump `RAIDERIO_SEED_CHAR_TTL=86400` and `BLIZZARD_CRAWL_RECENT_THRESHOLD=259200`.
- `backend/config/raiderio.php` — bump default of `character_resync_ttl` from `12 * 3600` to `24 * 3600`.
- `backend/config/blizzard.php` — bump default of `crawl.recent_threshold` from `21600` to `259200`.

**Create (tests):**
- `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php`
- `backend/tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php`
- `backend/tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php`
- `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`
- `backend/tests/Feature/Http/GuildControllerEagerLoadTest.php`

---

## Task 1: Bump env + config defaults

**Files:**
- Modify: `backend/config/raiderio.php:33`
- Modify: `backend/config/blizzard.php:101`
- Modify: `backend/.env.example:88`
- Modify: `backend/.env.example:100`

- [ ] **Step 1: Bump `character_resync_ttl` default**

In `backend/config/raiderio.php` change line 33:

```php
'character_resync_ttl' => (int) env('RAIDERIO_SEED_CHAR_TTL', 24 * 3600),
```

(was `12 * 3600`).

- [ ] **Step 2: Bump `crawl.recent_threshold` default**

In `backend/config/blizzard.php` change line 101:

```php
'recent_threshold' => (int) env('BLIZZARD_CRAWL_RECENT_THRESHOLD', 259200),
```

(was `21600`).

- [ ] **Step 3: Update `.env.example`**

In `backend/.env.example` line 88:

```
BLIZZARD_CRAWL_RECENT_THRESHOLD=259200
```

In `backend/.env.example` line 100:

```
RAIDERIO_SEED_CHAR_TTL=86400
```

- [ ] **Step 4: Run the existing test suite to confirm no regressions from default changes**

Run: `cd backend && composer test`
Expected: full suite green. The existing `SyncGuildRosterCharacterFanoutTest` overrides `raiderio.character_resync_ttl` per-test (`config()->set(...)`) so it's insulated from the default bump. If any unrelated test fails because it implicitly relied on the old defaults, note the failure and fix it before continuing.

- [ ] **Step 5: Commit**

```bash
cd backend
git add config/raiderio.php config/blizzard.php .env.example
git commit -m "chore(config): bump roster Full TTL to 24h and teammate crawl freshness to 3d"
```

---

## Task 2: Add `forceCascade` constructor param to `SyncGuildData` (no behavior yet)

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncGuildData.php:36-46`
- Test: `backend/tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php` — verify the existing not-found test still constructs the job correctly (sanity check, no new assertions needed).

- [ ] **Step 1: Write the failing test for the new constructor param**

Create `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncGuildData;
use Tests\TestCase;

class SyncGuildDataForceCascadeTest extends TestCase
{
    public function test_force_cascade_constructor_param_defaults_false(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'Echo');

        $this->assertFalse($job->forceCascade);
    }

    public function test_force_cascade_can_be_set_true(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'Echo', forceCascade: true);

        $this->assertTrue($job->forceCascade);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`
Expected: FAIL — `Unknown named parameter $forceCascade` and `Undefined property forceCascade`.

- [ ] **Step 3: Add the `forceCascade` param**

In `backend/app/Blizzard/Jobs/SyncGuildData.php`, replace the constructor (lines 36-46) with:

```php
    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        // Non-readonly with property-default so unserialize of old-shape queued
        // jobs gets `false` rather than "uninitialized" — see SyncCharacterData
        // forceTeammateCrawl for the same pattern + rationale.
        public bool $forceRosterFanout = false,
        // Set true by user-visit dispatch sites (GuildController, GuildService)
        // to force per-member Full fan-out + M+ teammate crawl on the resulting
        // SyncGuildRoster. Default false so background ProactiveSyncGuilds stays
        // Shallow-only.
        public bool $forceCascade = false,
    ) {
        $this->onQueue('blizzard-user-sync');
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`
Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
cd backend
git add app/Blizzard/Jobs/SyncGuildData.php tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php
git commit -m "feat(jobs): add forceCascade param to SyncGuildData"
```

---

## Task 3: Wire `forceCascade` to `SyncGuildRoster::dispatch` from `SyncGuildData::handle`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncGuildData.php:142` (`SyncGuildRoster::dispatch` call)
- Test: `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`

- [ ] **Step 1: Add a failing test that asserts SyncGuildRoster gets forceFanout=true when forceCascade=true**

Append to `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`. Replace the file's contents with:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SyncGuildDataForceCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_force_cascade_constructor_param_defaults_false(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'Echo');

        $this->assertFalse($job->forceCascade);
    }

    public function test_force_cascade_can_be_set_true(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'Echo', forceCascade: true);

        $this->assertTrue($job->forceCascade);
    }

    public function test_dispatches_sync_guild_roster_with_force_fanout_when_force_cascade_true(): void
    {
        Bus::fake([SyncGuildRoster::class]);

        $this->stubGuildClientReturning(
            profile: ['name' => 'Echo', 'faction' => ['type' => 'HORDE'], 'achievement_points' => 0,
                      'member_count' => 1, 'created_timestamp' => 0, 'realm' => ['name' => 'Tarren Mill']],
            roster: [],
        );

        (new SyncGuildData('eu', 'tarren-mill', 'echo', forceCascade: true))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === true);
    }

    public function test_dispatches_sync_guild_roster_without_force_fanout_when_force_cascade_false(): void
    {
        Bus::fake([SyncGuildRoster::class]);

        $this->stubGuildClientReturning(
            profile: ['name' => 'Echo', 'faction' => ['type' => 'HORDE'], 'achievement_points' => 0,
                      'member_count' => 1, 'created_timestamp' => 0, 'realm' => ['name' => 'Tarren Mill']],
            roster: [],
        );

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === false);
    }

    private function stubGuildClientReturning(array $profile, array $roster): void
    {
        $client = Mockery::mock(BlizzardProfileClient::class);
        $client->shouldReceive('getGuildData')->andReturn($profile);
        $client->shouldReceive('getGuildRoster')->andReturn(['members' => $roster]);
        $this->app->instance(BlizzardProfileClient::class, $client);
    }
}
```

> Note: `SyncGuildData::handle` instantiates `BlizzardProfileClient` via `new` (not container), so the mock binding above will not actually intercept. The two new tests need a different stub strategy — see Step 2.

- [ ] **Step 2: Replace the client stub with `Http::fake` (handles the `new BlizzardProfileClient` instantiation)**

Update the test file. Replace the `stubGuildClientReturning` helper and the two new tests' setup so they use `Http::fake` against the Blizzard profile-region URL pattern. Final test file:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SyncGuildDataForceCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TokenManager returns a fake bearer; avoids real OAuth round-trip.
        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_force_cascade_constructor_param_defaults_false(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo');
        $this->assertFalse($job->forceCascade);
    }

    public function test_force_cascade_can_be_set_true(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'echo', forceCascade: true);
        $this->assertTrue($job->forceCascade);
    }

    public function test_dispatches_sync_guild_roster_with_force_fanout_when_force_cascade_true(): void
    {
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo', forceCascade: true))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === true);
    }

    public function test_dispatches_sync_guild_roster_without_force_fanout_when_force_cascade_false(): void
    {
        Bus::fake([SyncGuildRoster::class]);
        $this->fakeBlizzardGuildEndpoints();

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        Bus::assertDispatched(SyncGuildRoster::class, fn (SyncGuildRoster $job) => $job->forceFanout === false);
    }

    private function fakeBlizzardGuildEndpoints(): void
    {
        Http::fake([
            '*/data/wow/guild/*/echo*' => Http::response([
                'name' => 'Echo',
                'faction' => ['type' => 'HORDE'],
                'achievement_points' => 0,
                'member_count' => 0,
                'created_timestamp' => 0,
                'realm' => ['name' => 'Tarren Mill', 'slug' => 'tarren-mill'],
            ]),
            '*/data/wow/guild/*/echo/roster*' => Http::response(['members' => []]),
        ]);
    }
}
```

> Caveat: confirm the URL fragments in `Http::fake(['...echo...' => ...])` actually match the ones produced by `BlizzardProfileClient::getGuildData/getGuildRoster`. If they don't match, run the test once and inspect with `Http::assertSent` to discover the exact pattern, then adjust. **Don't proceed past Step 3 until both new tests pass on a faked HTTP layer.**

- [ ] **Step 3: Run the test to verify the two new tests fail (forceFanout dispatch path not yet wired)**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`
Expected: two new tests FAIL because `SyncGuildData::handle` currently passes only `$this->forceRosterFanout`, not `$this->forceCascade`, into `SyncGuildRoster::dispatch`.

- [ ] **Step 4: Update `SyncGuildData::handle` to pass forceFanout when forceCascade is set**

In `backend/app/Blizzard/Jobs/SyncGuildData.php` line 142, change:

```php
        SyncGuildRoster::dispatch($guild, $this->forceRosterFanout);
```

to:

```php
        SyncGuildRoster::dispatch($guild, $this->forceRosterFanout || $this->forceCascade);
```

- [ ] **Step 5: Run the test to verify all four pass**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php`
Expected: 4 tests pass.

- [ ] **Step 6: Commit**

```bash
cd backend
git add app/Blizzard/Jobs/SyncGuildData.php tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php
git commit -m "feat(jobs): SyncGuildData forwards forceCascade as forceFanout to SyncGuildRoster"
```

---

## Task 4: Pre-resolve `character_id` in `SyncGuildData::handle` for `GuildMember::upsert`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncGuildData.php:104-126`
- Create: `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncGuildData;
use App\Blizzard\Jobs\SyncGuildRoster;
use App\Blizzard\Mappers\GuildProfileMapper;
use App\Blizzard\Mappers\GuildRosterMapper;
use App\Models\Character;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SyncGuildDataLinksExistingCharacterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SyncGuildRoster::class]);

        $tokenManager = Mockery::mock(TokenManagerInterface::class);
        $tokenManager->shouldReceive('getToken')->andReturn('fake-token');
        $this->app->instance(TokenManagerInterface::class, $tokenManager);
    }

    public function test_links_existing_character_to_guild_member_on_first_roster_sync(): void
    {
        Character::factory()->create([
            'name' => 'alpha',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $this->fakeRosterWith([
            ['name' => 'Alpha', 'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
            ['name' => 'Beta',  'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 2, 'race_id' => 1, 'rank' => 1],
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $alphaMember = GuildMember::where('name', 'alpha')->firstOrFail();
        $this->assertNotNull($alphaMember->character_id, 'expected alpha to be linked to its existing Character');

        $betaMember = GuildMember::where('name', 'beta')->firstOrFail();
        $this->assertNull($betaMember->character_id, 'beta has no Character row, must remain null');
    }

    public function test_backfills_character_id_on_subsequent_run_after_character_appears(): void
    {
        // First run: no Character yet — both members land NULL.
        $this->fakeRosterWith([
            ['name' => 'Gamma', 'realm' => 'Tarren Mill', 'level' => 80, 'class_id' => 1, 'race_id' => 1, 'rank' => 0],
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $this->assertNull(GuildMember::where('name', 'gamma')->firstOrFail()->character_id);

        // Now Character appears; second run should backfill character_id via the upsert update column list.
        $character = Character::factory()->create([
            'name' => 'gamma',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        (new SyncGuildData('eu', 'tarren-mill', 'echo'))->handle(
            $this->app->make(TokenManagerInterface::class),
            $this->app->make(GuildProfileMapper::class),
            $this->app->make(GuildRosterMapper::class),
        );

        $this->assertSame($character->id, GuildMember::where('name', 'gamma')->firstOrFail()->character_id);
    }

    private function fakeRosterWith(array $members): void
    {
        Http::fake([
            '*/data/wow/guild/*/echo/roster*' => Http::response([
                'members' => array_map(fn ($m) => [
                    'character' => [
                        'name' => $m['name'],
                        'realm' => ['name' => $m['realm'], 'slug' => strtolower(str_replace(' ', '-', $m['realm']))],
                        'level' => $m['level'],
                        'playable_class' => ['id' => $m['class_id']],
                        'playable_race' => ['id' => $m['race_id']],
                    ],
                    'rank' => $m['rank'],
                ], $members),
            ]),
            '*/data/wow/guild/*/echo*' => Http::response([
                'name' => 'Echo',
                'faction' => ['type' => 'HORDE'],
                'achievement_points' => 0,
                'member_count' => count($members),
                'created_timestamp' => 0,
                'realm' => ['name' => 'Tarren Mill', 'slug' => 'tarren-mill'],
            ]),
        ]);
    }
}
```

> The Http::fake URL ordering matters — the more specific pattern (`/roster*`) must come before the looser `/echo*`. Laravel's `Http::fake` matches in order.
> Cross-check the actual roster JSON shape consumed by `GuildRosterMapper` if the test fails — `app/Blizzard/Mappers/GuildRosterMapper.php` is authoritative.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php`
Expected: both tests FAIL — `character_id` is NULL because the upsert path does not pre-resolve it.

- [ ] **Step 3: Pre-resolve characters before the upsert**

In `backend/app/Blizzard/Jobs/SyncGuildData.php`, after `$members = $rosterMapper->map($rosterData);` (line 102) and before the `foreach` building `$memberRecords`, add:

```php
        // Pre-resolve character_id for each (name, realm) tuple so the upsert
        // can wire the FK in one round-trip per roster sync, avoiding the
        // GuildController stitch-by-tuple workaround.
        $charsByTuple = \App\Models\Character::query()
            ->where('region', $this->region)
            ->where('game_version', 'retail')
            ->where(function ($q) use ($members) {
                foreach ($members as $m) {
                    $q->orWhere(fn ($qq) => $qq->where('name', $m->name)->where('realm', $m->realm));
                }
            })
            ->get(['id', 'name', 'realm'])
            ->keyBy(fn ($c) => $c->name . '|' . $c->realm);
```

(prefer adding `use App\Models\Character;` at the top of the file rather than inline `\App\Models\Character` — clean up the import path before committing.)

Then in the `foreach ($members as $member)` loop (lines 105-117), add a `'character_id'` key to each `$memberRecords[]` entry:

```php
            $memberRecords[] = [
                'guild_id' => $guild->id,
                'character_id' => $charsByTuple["{$member->name}|{$member->realm}"]?->id ?? null,
                'name' => $member->name,
                'realm' => $member->realm,
                'level' => $member->level,
                'class_id' => $member->classId,
                'race_id' => $member->raceId,
                'rank' => $member->rank,
                'display_name' => $member->displayName,
                'display_realm' => $member->displayRealm,
            ];
```

And in the `GuildMember::upsert` call (lines 121-125) extend the update-column list to include `character_id`:

```php
            GuildMember::upsert(
                $memberRecords,
                ['guild_id', 'name', 'realm'],
                ['character_id', 'level', 'class_id', 'race_id', 'rank', 'display_name', 'display_realm'],
            );
```

- [ ] **Step 4: Run the test to verify both pass**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php`
Expected: 2 tests pass.

- [ ] **Step 5: Run the full Blizzard job suite to catch any regression on existing tests**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard tests/Unit/Blizzard`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
cd backend
git add app/Blizzard/Jobs/SyncGuildData.php tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php
git commit -m "feat(jobs): SyncGuildData pre-resolves character_id when upserting GuildMembers"
```

---

## Task 5: Backfill `GuildMember.character_id` from `SyncCharacterData`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncCharacterData.php` — after the `Character::updateOrCreate` block (~line 209-217)
- Create: `backend/tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCharacterDataLinksGuildMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_existing_guild_member_in_same_region_after_character_upsert(): void
    {
        $guildEu = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill', 'name' => 'echo']);
        $member = GuildMember::factory()->create([
            'guild_id' => $guildEu->id,
            'character_id' => null,
            'name' => 'delta',
            'realm' => 'tarren-mill',
        ]);

        $character = Character::factory()->create([
            'name' => 'delta',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);

        // Run only the backfill closure under test by invoking it directly.
        // (The full handle() requires HTTP; we test the pure-DB linker in isolation.)
        $this->artisan('tinker', [])->stopOnFailure(); // sanity: tinker available
        // Direct call to the production code path, extracted as a static helper in Step 3.
        \App\Blizzard\Jobs\SyncCharacterData::linkGuildMembers($character);

        $this->assertSame($character->id, $member->fresh()->character_id);
    }

    public function test_does_not_link_guild_member_in_different_region(): void
    {
        $guildUs = Guild::factory()->create(['region' => 'us', 'realm' => 'tarren-mill', 'name' => 'echo-us']);
        $member = GuildMember::factory()->create([
            'guild_id' => $guildUs->id,
            'character_id' => null,
            'name' => 'epsilon',
            'realm' => 'tarren-mill',
        ]);

        $character = Character::factory()->create([
            'name' => 'epsilon',
            'realm' => 'tarren-mill',
            'region' => 'eu',  // different region
            'game_version' => 'retail',
        ]);

        \App\Blizzard\Jobs\SyncCharacterData::linkGuildMembers($character);

        $this->assertNull($member->fresh()->character_id, 'cross-region link must not happen');
    }

    public function test_no_op_when_guild_member_already_linked(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        $other = Character::factory()->create([
            'name' => 'zeta', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        $member = GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => $other->id,  // already linked to a different (stale) character row
            'name' => 'zeta',
            'realm' => 'tarren-mill',
        ]);

        $current = Character::factory()->create([
            'name' => 'zeta', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);

        \App\Blizzard\Jobs\SyncCharacterData::linkGuildMembers($current);

        // whereNull guard means we leave the existing link alone — current behavior.
        $this->assertSame($other->id, $member->fresh()->character_id);
    }
}
```

> The test reaches into a static helper `SyncCharacterData::linkGuildMembers($character)` that we'll extract in Step 3. This keeps the test pure-DB (no HTTP fakes needed). If you'd rather inline the logic in `handle()` directly, drop the helper and write a feature test that runs the full `handle()` with `Http::fake` — but that's substantially more setup.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php`
Expected: 3 tests FAIL — `linkGuildMembers` static method does not exist.

- [ ] **Step 3: Add the static helper and call it from `handle()`**

In `backend/app/Blizzard/Jobs/SyncCharacterData.php`:

a) Add the static method (place it just below `persistRunTeam()` so the helpers cluster together):

```php
    /**
     * Backfill GuildMember.character_id for any rows whose (name, realm, guild.region)
     * matches the given character. Idempotent: only fills NULLs, never overwrites.
     * Public-static so it can be unit-tested without driving the full handle() path.
     */
    public static function linkGuildMembers(Character $character): void
    {
        \App\Models\GuildMember::query()
            ->where('name', $character->name)
            ->where('realm', $character->realm)
            ->whereNull('character_id')
            ->whereHas('guild', fn ($q) => $q->where('region', $character->region))
            ->update(['character_id' => $character->id]);
    }
```

(prefer `use App\Models\GuildMember;` at the top of the file).

b) In `handle()`, immediately after `$character = Character::updateOrCreate(...)` (~line 217) and before the guild-link block (line 219), add:

```php
        self::linkGuildMembers($character);
```

- [ ] **Step 4: Run the unit test to verify all three pass**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php`
Expected: 3 tests pass.

- [ ] **Step 5: Run the full Blizzard suite to catch regressions**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard tests/Unit/Blizzard`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
cd backend
git add app/Blizzard/Jobs/SyncCharacterData.php tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php
git commit -m "feat(jobs): SyncCharacterData backfills GuildMember.character_id after upsert"
```

---

## Task 6: Unify TTL gate covering Shallow + Full in `SyncGuildRoster`; thread `forceTeammateCrawl`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncGuildRoster.php:54-106` (`handle()` + `dispatchFullSyncsForMembers()`)
- Create: `backend/tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php`:

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
use Tests\TestCase;

class SyncGuildRosterTtlGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config()->set('raiderio.character_resync_ttl', 86400); // 24h
        config()->set('blizzard.min_level_for_character_lookup', 70);
    }

    public function test_skips_shallow_and_full_for_fresh_member_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'fresh', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        Character::factory()->create([
            'name' => 'fresh', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        Character::where('name', 'fresh')->update(['updated_at' => now()->subMinutes(5)]); // fresh

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'fresh',
        );
    }

    public function test_dispatches_shallow_and_full_for_stale_member_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'stale', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        Character::factory()->create([
            'name' => 'stale', 'realm' => 'tarren-mill', 'region' => 'eu', 'game_version' => 'retail',
        ]);
        Character::where('name', 'stale')->update(['updated_at' => now()->subDays(2)]); // stale

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'stale' && $j->depth === SyncDepth::Shallow,
        );
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'stale' && $j->depth === SyncDepth::Full,
        );
    }

    public function test_dispatches_shallow_and_full_for_cold_member_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'cold', 'realm' => 'tarren-mill', 'level' => 80,
        ]);
        // No Character row at all — coldest case.

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'cold' && $j->depth === SyncDepth::Shallow,
        );
        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'cold' && $j->depth === SyncDepth::Full,
        );
    }

    public function test_full_dispatches_carry_force_teammate_crawl_when_force_fanout(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'cold', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        (new SyncGuildRoster($guild, forceFanout: true))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'cold'
                && $j->depth === SyncDepth::Full
                && $j->forceTeammateCrawl === true,
        );
    }

    public function test_shallow_still_dispatched_for_cold_member_when_global_flag_off_and_no_force_fanout(): void
    {
        // Default proactive path: forceFanout=false, config flag=false.
        // We still dispatch Shallow today (existing behavior); only Full is gated off.
        // After this task, Shallow is also gated by the TTL but only when forceFanout=true.
        // When neither force nor flag is set, dispatchFullSyncsForMembers is skipped entirely
        // (the existing `if ($this->forceFanout || config(...))` guard) — but the unconditional
        // Shallow loop still fires.
        config()->set('raiderio.dispatch_roster_character_syncs', false);

        $guild = Guild::factory()->create(['region' => 'eu', 'realm' => 'tarren-mill']);
        GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'proactive', 'realm' => 'tarren-mill', 'level' => 80,
        ]);

        (new SyncGuildRoster($guild, forceFanout: false))->handle();

        Bus::assertDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'proactive' && $j->depth === SyncDepth::Shallow,
        );
        Bus::assertNotDispatched(
            SyncCharacterData::class,
            fn (SyncCharacterData $j) => $j->name === 'proactive' && $j->depth === SyncDepth::Full,
        );
    }
}
```

> Decision: the unified TTL gate **only applies when `forceFanout=true`**. Proactive sweeps (`forceFanout=false`) keep today's behavior — Shallow always fires. Rationale: ProactiveSyncCharacters tier-1 already runs at 30 min, and we don't want to lose Shallow profile updates on the proactive tier just because a member's Character was synced via some other path.

- [ ] **Step 2: Run the test to verify the new TTL-gated assertions fail**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php`
Expected: `test_skips_shallow_and_full_for_fresh_member_when_force_fanout` and `test_full_dispatches_carry_force_teammate_crawl_when_force_fanout` FAIL. The other tests may pass with current behavior.

- [ ] **Step 3: Refactor `SyncGuildRoster::handle()` to apply unified TTL when forceFanout**

In `backend/app/Blizzard/Jobs/SyncGuildRoster.php` replace the entire `handle()` method (lines 54-86) with:

```php
    public function handle(): void
    {
        $minLevel = (int) config('blizzard.min_level_for_character_lookup', 70);

        $members = $this->guild->members()
            ->where('level', '>=', $minLevel)
            ->get();

        // When forceFanout is true (user-visit cascade or seeder run), skip both
        // Shallow and Full dispatches for any member whose Character row is fresh
        // (updated_at within $ttl). Cold + stale members get both Shallow and Full.
        // When forceFanout is false (proactive path), Shallow fires unconditionally
        // — same as today.
        $freshTuples = [];
        if ($this->forceFanout) {
            $ttl = (int) config('raiderio.character_resync_ttl', 86400);
            $cutoff = now()->subSeconds($ttl);

            $freshTuples = \App\Models\Character::query()
                ->where('region', $this->guild->region)
                ->where('game_version', 'retail')
                ->where('updated_at', '>', $cutoff)
                ->where(function ($q) use ($members) {
                    foreach ($members as $m) {
                        $q->orWhere(fn ($qq) => $qq->where('name', $m->name)->where('realm', $m->realm));
                    }
                })
                ->get(['name', 'realm'])
                ->mapWithKeys(fn ($c) => ["{$c->name}|{$c->realm}" => true])
                ->all();
        }

        foreach ($members as $member) {
            if ($this->forceFanout && isset($freshTuples["{$member->name}|{$member->realm}"])) {
                continue;
            }

            SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Shallow,
            );
        }

        // Per-member SyncCharacterData::Full fan-out is gated by either:
        // 1. forceFanout=true on this specific job (set by the seeder via SyncGuildData,
        //    or by the user-visit cascade via SyncGuildData::forceCascade).
        // 2. raiderio.dispatch_roster_character_syncs config flag (default false).
        if ($this->forceFanout || config('raiderio.dispatch_roster_character_syncs', false)) {
            $this->dispatchFullSyncsForMembers($members, $freshTuples);
        }
    }
```

(prefer `use App\Models\Character;` at the top of the file rather than inline `\App\Models\Character`.)

- [ ] **Step 4: Refactor `dispatchFullSyncsForMembers` to accept the precomputed fresh-tuple map**

In the same file, replace `dispatchFullSyncsForMembers` (lines 88-106) with:

```php
    /**
     * @param  array<string, true>  $freshTuples  precomputed map of "name|realm" => true for members
     *                                            whose Character is fresh under the unified TTL gate.
     *                                            Empty when forceFanout was false (proactive path
     *                                            uses its own self-contained TTL gate below).
     */
    protected function dispatchFullSyncsForMembers(Collection $members, array $freshTuples = []): void
    {
        $ttl = (int) config('raiderio.character_resync_ttl', 86400);
        $cutoff = now()->subSeconds($ttl);

        foreach ($members as $member) {
            // Unified gate (already computed) takes precedence when forceFanout was true.
            if ($this->forceFanout && isset($freshTuples["{$member->name}|{$member->realm}"])) {
                continue;
            }

            // Proactive path (forceFanout=false, config flag=true) falls back to per-member lookup.
            if (! $this->forceFanout) {
                $existing = Character::byIdentity($member->name, $member->realm, $this->guild->region)->first();
                if ($existing !== null && $existing->updated_at !== null && $existing->updated_at->isAfter($cutoff)) {
                    continue;
                }
            }

            SyncCharacterData::dispatch(
                region: $this->guild->region,
                realm: $member->realm,
                name: $member->name,
                depth: SyncDepth::Full,
                userId: null,
                crawlDepth: 0,
                forceTeammateCrawl: $this->forceFanout,
            );
        }
    }
```

> The `SyncCharacterData::dispatch` named-arg list relies on the constructor's parameter order (region, realm, name, depth, userId, crawlDepth, forceTeammateCrawl). Verify by re-reading `SyncCharacterData::__construct`. Pass `null` for `userId` and `0` for `crawlDepth` to keep parity with the original positional behavior.

- [ ] **Step 5: Run the new TTL-gate test to verify all 5 pass**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php`
Expected: 5 tests pass.

- [ ] **Step 6: Run the existing roster-fanout tests to confirm no regression**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncGuildRosterCharacterFanoutTest.php`
Expected: 5 tests pass (same as before).

- [ ] **Step 7: Run the full Blizzard suite**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Blizzard tests/Unit/Blizzard`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
cd backend
git add app/Blizzard/Jobs/SyncGuildRoster.php tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php
git commit -m "feat(jobs): SyncGuildRoster unified 24h TTL gate covers Shallow+Full when forceFanout"
```

---

## Task 7: Pass `forceCascade: true` from user-visit dispatch sites

**Files:**
- Modify: `backend/app/Http/Controllers/GuildController.php:34` (missing-guild dispatch)
- Modify: `backend/app/Services/GuildService.php:30-32` (stale-guild dispatch)

- [ ] **Step 1: Update GuildController missing-guild dispatch**

In `backend/app/Http/Controllers/GuildController.php` line 34, change:

```php
            SyncGuildData::dispatch($region, $realm, $guild);
```

to:

```php
            SyncGuildData::dispatch($region, $realm, $guild, forceRosterFanout: false, forceCascade: true);
```

(Explicit `forceRosterFanout: false` keeps named-arg semantics clear; the seeder is the only caller that passes `forceRosterFanout: true`.)

- [ ] **Step 2: Update GuildService stale-guild dispatch**

In `backend/app/Services/GuildService.php` around line 30-32, change:

```php
        if ($guild->isStale() || $guild->isRosterStale()) {
            SyncGuildData::dispatch($region, $realm, $name);
        }
```

to:

```php
        if ($guild->isStale() || $guild->isRosterStale()) {
            SyncGuildData::dispatch($region, $realm, $name, forceRosterFanout: false, forceCascade: true);
        }
```

- [ ] **Step 3: Run the full test suite to ensure no regression**

Run: `cd backend && composer test`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
cd backend
git add app/Http/Controllers/GuildController.php app/Services/GuildService.php
git commit -m "feat(guild): user-visit dispatch sites pass forceCascade=true to SyncGuildData"
```

---

## Task 8: Remove the GuildController stitch block

**Files:**
- Modify: `backend/app/Http/Controllers/GuildController.php:13` (remove `App\Models\Character` import)
- Modify: `backend/app/Http/Controllers/GuildController.php:54-86` (remove stitch block)
- Create: `backend/tests/Feature/Http/GuildControllerEagerLoadTest.php`

- [ ] **Step 1: Write the test that asserts the eager-loaded payload exposes per-member iLvl/M+/spec**

Create `backend/tests/Feature/Http/GuildControllerEagerLoadTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildControllerEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_member_character_columns_via_eager_load(): void
    {
        $guild = Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'echo',
            // make it fresh so SyncGuildData isn't dispatched on this request
            'roster_synced_at' => now(),
            'updated_at' => now(),
        ]);

        $character = Character::factory()->create([
            'name' => 'wired',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'equipped_item_level' => 642,
            'mythic_plus_rating' => 3210,
            'mythic_plus_rating_color' => '#ff8000',
            'active_specialization_id' => 71,
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'name' => 'wired',
            'realm' => 'tarren-mill',
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');

        $response->assertOk();
        $response->assertJsonPath('members.data.0.equipped_item_level', 642);
        $response->assertJsonPath('members.data.0.mythic_plus_rating', 3210);
    }

    public function test_show_returns_null_member_columns_when_character_id_is_null(): void
    {
        // After dropping the stitch block, an unlinked member should expose the
        // GuildMember row's own columns but NULL for character-only fields.
        // (Roster columns degrade gracefully — that's the contract.)
        $guild = Guild::factory()->create([
            'region' => 'eu',
            'realm' => 'tarren-mill',
            'name' => 'echo',
            'roster_synced_at' => now(),
            'updated_at' => now(),
        ]);

        GuildMember::factory()->create([
            'guild_id' => $guild->id,
            'character_id' => null,
            'name' => 'unlinked',
            'realm' => 'tarren-mill',
        ]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');

        $response->assertOk();
        $response->assertJsonPath('members.data.0.equipped_item_level', null);
    }
}
```

> Confirm the JSON keys (`equipped_item_level`, `mythic_plus_rating`) match what `GuildMemberResource` actually emits. If the resource uses different keys (e.g., camelCase), update the assertions.

- [ ] **Step 2: Run the test to verify the first one passes against the current code (stitch block still in place)**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Http/GuildControllerEagerLoadTest.php`
Expected: 2 tests pass — both should already work because the eager load + stitch block both produce the same payload. If they fail, verify the JSON keys match `GuildMemberResource`.

- [ ] **Step 3: Remove the stitch block and the unused import**

In `backend/app/Http/Controllers/GuildController.php` line 13, remove:

```php
use App\Models\Character;
```

In the same file, remove the entire stitch block (lines 54-86). Result for `show()`:

```php
    public function show(string $region, string $realm, string $guild, GuildService $service, Request $request): JsonResponse
    {
        $realm = BlizzardIdentity::realm($realm);
        $guild = BlizzardIdentity::realm($guild);

        try {
            $result = $service->getByIdentity($region, $realm, $guild);
        } catch (EntityNotFoundException) {
            return response()->json(['message' => 'Guild not found'], 404);
        }

        if ($result === null) {
            SyncGuildData::dispatch($region, $realm, $guild, forceRosterFanout: false, forceCascade: true);

            return response()->json(['message' => 'Guild sync initiated'], 202)
                ->header('Retry-After', '5');
        }

        $perPage = (int) $request->query('per_page', '50');
        $filter = trim((string) $request->query('filter', ''));

        $query = $result->members()
            ->with(['character:id,equipped_item_level,mythic_plus_rating,mythic_plus_rating_color,active_specialization_id,updated_at']);

        if ($filter !== '') {
            $query->where('name', 'LIKE', '%' . strtolower($filter) . '%');
        }

        $members = $query->paginate($perPage);
        $members = $members->through(fn ($member) => (new GuildMemberResource($member))->toArray($request));

        $response = response()->json([
            'guild' => new GuildResource($result),
            'members' => $members,
        ]);

        if ($result->isStale()) {
            $response->header('X-Data-Staleness', 'stale');
        }

        return $response;
    }
```

- [ ] **Step 4: Re-run the controller test to verify both still pass without the stitch**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Http/GuildControllerEagerLoadTest.php`
Expected: 2 tests pass.

- [ ] **Step 5: Run the full test suite**

Run: `cd backend && composer test`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
cd backend
git add app/Http/Controllers/GuildController.php tests/Feature/Http/GuildControllerEagerLoadTest.php
git commit -m "refactor(guild): drop stitch-by-tuple workaround now that character_id FK is wired"
```

---

## Task 9: Smoke test the cascade end-to-end

**Files:** none — manual verification.

- [ ] **Step 1: Restart Horizon (mandatory after job edits — opcache is frozen in container)**

```bash
docker compose -p guild-service-be-v2 restart horizon
```

- [ ] **Step 2: Reset DB and re-seed a small guild dataset**

```bash
cd backend
docker compose -p guild-service-be-v2 exec app php artisan migrate:fresh
docker compose -p guild-service-be-v2 exec app php artisan raiderio:seed --phase=guilds --limit=5 --regions=eu
```

- [ ] **Step 3: Observe Horizon while the queue drains**

Open the Horizon dashboard (or `docker compose -p guild-service-be-v2 logs -f horizon`) and confirm:
- `blizzard-roster-sync` queue produces `SyncCharacterData::Shallow` and `Full` jobs per roster member.
- `blizzard-background` queue picks up teammate-crawl `SyncCharacterData::Full` jobs (depth-2 capped).
- No `RuntimeException` on Bus::batch (regression guard for the older bug).

- [ ] **Step 4: Hit the FE and verify the roster columns are populated**

```bash
curl -s 'http://127.0.0.1:8091/api/v1/guilds/eu/<seeded-realm>/<seeded-guild>?per_page=50' | jq '.members.data[0] | {name, equipped_item_level, mythic_plus_rating, active_specialization_id}'
```

Expected: at least one row with `equipped_item_level` and `mythic_plus_rating` non-null (cascade has populated some Characters and the FK is wired).

- [ ] **Step 5: Verify a re-fetch within 24h is a near-no-op**

Run the same `curl` command 30 seconds later and confirm Horizon does NOT enqueue another wave of Full jobs (the unified TTL gate suppresses them).

- [ ] **Step 6: Document the smoke result in the commit message of Task 8 only if you found something off**

If everything looks healthy, no extra commit. If you found a defect, fix it and commit a follow-up under a clear `fix(...)` prefix.

---

## Self-Review

**Spec coverage:**
- ✅ Roster→Character FK wiring → Task 4.
- ✅ Character→Roster FK backfill → Task 5.
- ✅ Stitch block removal → Task 8.
- ✅ User-visit cascade plumbing (`forceCascade`) → Tasks 2, 3, 7.
- ✅ Shallow + Full unified TTL gate at 24h → Task 6.
- ✅ Teammate crawl freshness bumped to 3d via env → Task 1.
- ✅ Tests for both FK directions, TTL gate, force-cascade plumbing, eager-load eq with controller → Tasks 4, 5, 6, 3, 8.
- ✅ Migration / rollout instructions → Task 9.

**Type / signature consistency:**
- `SyncGuildData::__construct(..., bool $forceRosterFanout = false, bool $forceCascade = false)` — call sites in Tasks 7 and 8 use named args matching this signature. ✅
- `SyncGuildRoster::dispatchFullSyncsForMembers(Collection $members, array $freshTuples = [])` — only called from `handle()` in same file. ✅
- `SyncCharacterData::linkGuildMembers(Character $character)` static — called from `SyncCharacterData::handle()` and the new unit test, signature matches. ✅
- `SyncCharacterData::dispatch(...)` named-arg call in Task 6 Step 4 includes `userId: null`, `crawlDepth: 0`, `forceTeammateCrawl: $this->forceFanout` — verify against `SyncCharacterData::__construct` parameter list in `app/Blizzard/Jobs/SyncCharacterData.php:64-77` before running. ⚠️ Manual verify on first compile.

**Placeholder scan:** None remaining. Each step contains either runnable code, a runnable command, or a concrete file edit.

**Open assumptions to double-check on first compile:**
1. The `Http::fake` URL patterns in Tasks 3-4 must match what `BlizzardProfileClient::getGuildData/getGuildRoster` actually request. If the test fakes don't match, the handle() will hit the real network or throw — adjust patterns by inspecting the client code or using `Http::assertSent`.
2. `GuildMemberResource` JSON keys in Task 8's test (`equipped_item_level`, `mythic_plus_rating`) must match the resource's `toArray()` output. Read the resource before running.
3. `SyncCharacterData` constructor parameter ordering — Task 6 Step 4 uses positional+named args; verify against the source.

---

## Execution Handoff

**Plan complete and saved to `backend/docs/superpowers/plans/2026-05-03-guild-roster-fk-and-cascade.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using executing-plans, batch with checkpoints for review.

**Which approach?**
