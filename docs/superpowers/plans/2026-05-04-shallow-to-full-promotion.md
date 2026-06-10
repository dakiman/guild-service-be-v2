# Shallow-to-Full Character Promotion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-promote max-level shallow-synced characters to Full sync with teammate crawl.

**Architecture:** Single conditional dispatch added at the end of the Shallow branch in `SyncCharacterData::handle()`. Guarded by level check (80) and never-fully-synced check (`mythics_synced_at === null`).

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit, Bus::fake()

---

## File Structure

- **Modify:** `app/Blizzard/Jobs/SyncCharacterData.php` — add promotion dispatch after Shallow upsert
- **Create:** `tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php` — test the promotion logic

---

### Task 1: Write failing test for shallow promotion dispatch

**Files:**
- Create: `tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCharacterDataShallowPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shallow_sync_dispatches_full_for_max_level_never_fully_synced(): void
    {
        Bus::fake([SyncCharacterData::class]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            '*/profile/wow/character/*' => Http::response([
                'id' => 1,
                'name' => 'Testchar',
                'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
                'gender' => ['type' => 'MALE'],
                'faction' => ['type' => 'HORDE'],
                'race' => ['id' => 2],
                'character_class' => ['id' => 1],
                'level' => 80,
                'achievement_points' => 1000,
                'average_item_level' => 600,
                'equipped_item_level' => 595,
            ]),
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'testchar',
            depth: SyncDepth::Shallow,
        );
        $job->handle();

        Bus::assertDispatched(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full
                && $job->name === 'testchar'
                && $job->realm === 'tarren-mill'
                && $job->region === 'eu'
                && $job->forceTeammateCrawl === true;
        });
    }

    public function test_shallow_sync_skips_promotion_for_below_max_level(): void
    {
        Bus::fake([SyncCharacterData::class]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            '*/profile/wow/character/*' => Http::response([
                'id' => 2,
                'name' => 'Lowbie',
                'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
                'gender' => ['type' => 'FEMALE'],
                'faction' => ['type' => 'ALLIANCE'],
                'race' => ['id' => 1],
                'character_class' => ['id' => 5],
                'level' => 45,
                'achievement_points' => 200,
                'average_item_level' => 100,
                'equipped_item_level' => 95,
            ]),
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'lowbie',
            depth: SyncDepth::Shallow,
        );
        $job->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full;
        });
    }

    public function test_shallow_sync_skips_promotion_when_already_fully_synced(): void
    {
        Bus::fake([SyncCharacterData::class]);

        Character::factory()->create([
            'name' => 'veteran',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'mythics_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            '*/profile/wow/character/*' => Http::response([
                'id' => 3,
                'name' => 'Veteran',
                'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
                'gender' => ['type' => 'MALE'],
                'faction' => ['type' => 'HORDE'],
                'race' => ['id' => 2],
                'character_class' => ['id' => 1],
                'level' => 80,
                'achievement_points' => 5000,
                'average_item_level' => 620,
                'equipped_item_level' => 618,
            ]),
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'veteran',
            depth: SyncDepth::Shallow,
        );
        $job->handle();

        Bus::assertNotDispatched(SyncCharacterData::class, function (SyncCharacterData $job) {
            return $job->depth === SyncDepth::Full;
        });
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php`

Expected: First test (`test_shallow_sync_dispatches_full_for_max_level_never_fully_synced`) FAILS because no Full dispatch happens after Shallow. The other two should PASS (they assert non-dispatch which is the current behavior).

- [ ] **Step 3: Commit test file**

```bash
git add tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php
git commit -m "test: add shallow-to-full promotion test cases"
```

---

### Task 2: Implement the promotion dispatch

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php:148-159` (Shallow branch)

- [ ] **Step 1: Add the promotion logic after the Shallow upsert**

In `SyncCharacterData::handle()`, after line 226 (`self::linkGuildMembers($character)`), and before the guild-linking block (line 228), add:

```php
        // Promote max-level shallow-synced characters to Full + teammate crawl
        // so they fan out into the discovery pipeline without waiting for a user visit.
        if ($this->depth === SyncDepth::Shallow
            && $character->level === 80
            && $character->mythics_synced_at === null
        ) {
            self::dispatch(
                region: $this->region,
                realm: $this->realm,
                name: $this->name,
                depth: SyncDepth::Full,
                forceTeammateCrawl: true,
            );
        }
```

This goes immediately after `self::linkGuildMembers($character)` (line 226) and before the `// Link guild if present.` comment (line 228).

- [ ] **Step 2: Run the tests to verify they pass**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php`

Expected: All 3 tests PASS.

- [ ] **Step 3: Run the broader SyncCharacterData test suite to check for regressions**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && ./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/ --filter="SyncCharacterData|SyncGuildRoster"`

Expected: All existing tests PASS.

- [ ] **Step 4: Run pint to fix style**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && ./vendor/bin/pint app/Blizzard/Jobs/SyncCharacterData.php tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php tests/Feature/Blizzard/Jobs/SyncCharacterDataShallowPromotionTest.php
git commit -m "feat(blizzard): promote max-level shallow characters to Full + teammate crawl"
```
