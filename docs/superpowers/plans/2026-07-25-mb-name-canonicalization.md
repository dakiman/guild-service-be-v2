# Multibyte Name Canonicalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop non-ASCII (Cyrillic, `Æ`, accented) character names from creating case-duplicate `characters` rows, fix the downstream symptoms (duplicate top-performer entries, missing `mythic_plus_rating_by_spec`, broken Cyrillic search, unlinked guild members), and repair the existing data (~55k non-canonical `characters` rows incl. 28 duplicate identity groups, ~74.5k non-canonical `guild_members` rows).

**Architecture:** The codebase's documented invariant (see `backend/docs/superpowers/plans/2026-04-27-blizzard-name-normalization.md` and the comments in `Character::scopeNameSearch`) is: `characters.name` / `guild_members.name` are canonical **mb-lowercase** via `App\Support\BlizzardIdentity::name()` (`Str::lower`), with display casing preserved separately in `display_name`. Several background paths bypass this with PHP's ASCII-only `strtolower()`, which leaves non-ASCII letters uppercased. The fix: route every name normalization through `BlizzardIdentity::name()` (dispatch sites + one defense-in-depth chokepoint at the `Character::updateOrCreate` write site), fix mb-unsafe name *comparisons*, then run a one-off idempotent repair command.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (SQLite in-memory, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`), PostgreSQL in prod.

## Root-cause summary (diagnosed 2026-07-25)

- `strtolower('Бробабади')` → `'Бробабади'` (unchanged — bytes ≥ 0x80 untouched). `Str::lower()` / `BlizzardIdentity::name()` → `'бробабади'` (correct).
- `dungeon_run_members.character_name` stores **display-cased** names by design (see `RunTeamPersister` P1.3 comment). `SyncCharacterData::dispatchTeammateCrawl()` "lowercases" them with `strtolower()` → dispatches syncs keyed on capitalized names → `Character::updateOrCreate` creates a second row. Still happening daily at ~01:01 UTC after the raider.io seed.
- Same ASCII-only `strtolower()` in `GuildRosterMapper` (roster fan-out dispatch + `guild_members.name` rows) and `SyncUserCharacters`.
- `MythicPlusRatingMapper::aggregatePerSpec()` compares names with `strtolower()` → for non-ASCII characters synced under the canonical lowercase name, the member match fails → `mythic_plus_rating_by_spec` stays null (verified in prod: row 1456747 `бробабади` has `by_spec = null`, row 1232056 `Бробабади` has `{"252": 549}`).
- Search needles (`Character::scopeNameSearch`, `Guild::scopeNameSearch`, `GuildController` roster filter) `strtolower()` the user's query → Cyrillic search input never matches canonical rows. Also `strlen()` counts bytes, so a single Cyrillic char (2 bytes) passes the min-length gate.
- `SyncCharacterData::linkGuildMembers()` matches `guild_members.name = characters.name` exactly → the 74,578 capitalized `guild_members` rows never link (`character_id` stays NULL).
- `BlizzardProfileClient` already canonicalizes both casings to the same URL, so both duplicate rows sync "successfully" forever. Guilds are unaffected (0 non-canonical rows in prod).

**Deliberately NOT changed:** `RunTeamPersister` (stores display-cased pivot names on purpose; its matching is already mb-safe), `dungeon_run_members.character_name` values (they are display data; FE `capitalizeName()` handles rendering), `season_archives` payloads (immutable snapshots).

## Global Constraints

- Character rows and their relations (dungeon runs, raid kills, etc.) are accumulated crawl data — the repair must merge/repoint, **never casually delete** (slice-table leftovers that fully re-sync are the only exception, documented in Task 7).
- **NEVER add Claude attribution to commits** (no `Co-Authored-By: Claude`, no `🤖 Generated with` lines). Absolute rule from the user's global CLAUDE.md.
- Tests run on SQLite in-memory: all case operations MUST happen in PHP (`BlizzardIdentity::name()` / `Str::lower`), **never** SQL `lower()` (ASCII-only on SQLite; collation-dependent on PG).
- Raw SQL in the repair command must be portable across PG + SQLite (correlated subqueries: alias the inner table, reference the outer table by its unaliased name — SQLite rejects an alias on the UPDATE target).
- Commit prefix convention: `BE:` for backend changes (match `git log --oneline` style).
- Run `./vendor/bin/pint` on touched files before each commit.
- Verification per task: the specific new/extended test file, not the whole suite; run `composer test` once at the end (282+ tests must pass).
- Working directory for all commands: `/home/dakiman/dev/guild-service-v2/backend`.

## File structure

**New files:**
- `app/Console/Commands/CanonicalizeCharacterNames.php` — one-off idempotent repair command
- `tests/Unit/Blizzard/Mappers/GuildRosterMapperTest.php`
- `tests/Unit/Blizzard/Mappers/MythicPlusRatingMapperTest.php`
- `tests/Feature/Console/CanonicalizeCharacterNamesTest.php`

**Modified files:**
- `app/Blizzard/Mappers/GuildRosterMapper.php:25` — canonical member names
- `app/Blizzard/Jobs/SyncUserCharacters.php:79` — canonical dispatch names
- `app/Blizzard/Jobs/SyncCharacterData.php` — teammate-crawl canonicalization (~line 962) + write-site canonicalization (~line 287)
- `app/Blizzard/Mappers/MythicPlusRatingMapper.php:53,64` — mb-safe member matching
- `app/Models/Character.php:262`, `app/Models/Guild.php:117`, `app/Http/Controllers/GuildController.php:75` — mb-safe search needles
- `tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php` — extended
- `CLAUDE.md` (backend) — document the invariant

---

### Task 1: `GuildRosterMapper` — canonical member names

**Files:**
- Modify: `app/Blizzard/Mappers/GuildRosterMapper.php:25`
- Test: `tests/Unit/Blizzard/Mappers/GuildRosterMapperTest.php` (create)

**Interfaces:**
- Produces: `GuildMemberData->name` is now always `BlizzardIdentity::name()`-canonical; `->displayName` still carries the raw Blizzard casing. Downstream (`SyncGuildRoster` dispatch, `guild_members.name` upserts) inherits canonical names with no further changes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GuildRosterMapper;
use Tests\TestCase;

final class GuildRosterMapperTest extends TestCase
{
    private function rosterPayload(string $name): array
    {
        return [
            'members' => [
                [
                    'character' => [
                        'name' => $name,
                        'realm' => ['slug' => 'howling-fjord', 'name' => 'Howling Fjord'],
                        'level' => 90,
                        'playable_class' => ['id' => 6],
                        'playable_race' => ['id' => 86],
                    ],
                    'rank' => 3,
                ],
            ],
        ];
    }

    public function test_cyrillic_member_name_is_canonicalized_mb_lowercase(): void
    {
        $members = (new GuildRosterMapper)->map($this->rosterPayload('Бробабади'));

        $this->assertSame('бробабади', $members[0]->name);
        $this->assertSame('Бробабади', $members[0]->displayName);
    }

    public function test_ascii_member_name_still_lowercases(): void
    {
        $members = (new GuildRosterMapper)->map($this->rosterPayload('Melaniya'));

        $this->assertSame('melaniya', $members[0]->name);
        $this->assertSame('Melaniya', $members[0]->displayName);
    }
}
```

Note: before running, check `GuildRosterMapper`'s actual public method name and return shape (`map(...)` returning `GuildMemberData[]` per `app/Blizzard/Mappers/GuildRosterMapper.php` — the loop is over `$data['members']`). Adjust the test's method call to the real signature if it differs (e.g., a DTO wrapper); keep the two assertions unchanged.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GuildRosterMapperTest.php`
Expected: FAIL — `'Бробабади'` !== `'бробабади'` (ASCII test may already pass).

- [ ] **Step 3: Fix the mapper**

In `app/Blizzard/Mappers/GuildRosterMapper.php`, add `use App\Support\BlizzardIdentity;` and change line 25:

```php
// before
name: strtolower($rawName),
// after
name: BlizzardIdentity::name($rawName),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GuildRosterMapperTest.php`
Expected: PASS (both tests).

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Blizzard/Mappers/GuildRosterMapper.php tests/Unit/Blizzard/Mappers/GuildRosterMapperTest.php
git add -A && git commit -m "BE: mb-safe canonical names in GuildRosterMapper"
```

---

### Task 2: `SyncUserCharacters` — canonical dispatch names

**Files:**
- Modify: `app/Blizzard/Jobs/SyncUserCharacters.php:79`

**Interfaces:**
- Produces: every `SyncCharacterData::dispatch(name: ...)` from the Battle.net account sync now passes a canonical name.

This is a one-line change on a job whose `handle()` requires mocking `BlizzardUserClient` + a `User` model; the write-site test in Task 4 covers the invariant end-to-end, so no dedicated test file — rely on the existing suite for regressions.

- [ ] **Step 1: Fix the job**

In `app/Blizzard/Jobs/SyncUserCharacters.php`, add `use App\Support\BlizzardIdentity;` and change line 79:

```php
// before
$name = strtolower($character['name'] ?? '');
// after
$name = BlizzardIdentity::name($character['name'] ?? '');
```

- [ ] **Step 2: Run the job's neighborhood tests**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/`
Expected: PASS (no regressions).

- [ ] **Step 3: Pint + commit**

```bash
./vendor/bin/pint app/Blizzard/Jobs/SyncUserCharacters.php
git add -A && git commit -m "BE: mb-safe canonical names in SyncUserCharacters dispatch"
```

---

### Task 3: `SyncCharacterData::dispatchTeammateCrawl` — canonical crawl targets

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php:962,972`
- Test: `tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php` (extend)

**Interfaces:**
- Consumes: `dungeon_run_members.character_name` (display-cased by design — do NOT change `RunTeamPersister`).
- Produces: crawl dispatches always carry canonical names; the seed-skip comparison is mb-safe.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php` (reuse the existing `makeSeedWithRun()` fixture helper and `Bus::fake` assertion pattern already in that file — read the whole file first):

```php
public function test_display_cased_cyrillic_teammate_dispatches_canonical_name(): void
{
    Bus::fake();
    $seed = $this->makeSeedWithRun();
    $run = DungeonRun::query()->firstOrFail();

    DungeonRunMember::create([
        'dungeon_run_id' => $run->id,
        'character_id' => null,
        'character_name' => 'Бробабади',   // display-cased, as RunTeamPersister stores it
        'character_realm' => 'howling-fjord',
        'character_region' => 'eu',
    ]);

    $this->runCrawlForSeed($seed); // use the file's existing invocation helper/pattern

    Bus::assertDispatched(SyncCharacterData::class, function (SyncCharacterData $job) {
        return $job->name === 'бробабади' && $job->realm === 'howling-fjord';
    });
}

public function test_seed_skip_matches_display_cased_pivot_row_of_seed_itself(): void
{
    Bus::fake();
    // Seed named canonical 'бробабади'; its own pivot row stored display-cased.
    $seed = $this->makeSeedWithRun(); // then override/create seed + pivot with:
    //   seed:  name 'бробабади', realm 'howling-fjord', region 'eu'
    //   pivot: character_name 'Бробабади', same realm/region, linked to the run
    $this->runCrawlForSeed($seed);

    Bus::assertNotDispatched(SyncCharacterData::class, fn (SyncCharacterData $job) => $job->name === 'бробабади');
}
```

The exact helper names differ — mirror how the existing tests in this file create the seed, pivot rows, and trigger the crawl (they already exercise `dispatchTeammateCrawl` with `Config::set('blizzard.sync.teammate_crawl_enabled', true)`). The two behaviors to lock in: (a) dispatch carries the canonical name, (b) a display-cased pivot row of the seed itself is skipped.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php`
Expected: new tests FAIL (dispatched name is `'Бробабади'`; seed-skip misses).

- [ ] **Step 3: Fix the crawl**

In `app/Blizzard/Jobs/SyncCharacterData.php`, add `use App\Support\BlizzardIdentity;` and change the two lines in `dispatchTeammateCrawl()`:

```php
// before (line ~962)
$name = strtolower((string) $row->character_name);
// after
$name = BlizzardIdentity::name((string) $row->character_name);

// before (line ~972)
if ($name === strtolower($this->name)
// after
if ($name === BlizzardIdentity::name($this->name)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php`
Expected: PASS (all tests in file).

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Blizzard/Jobs/SyncCharacterData.php tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php
git add -A && git commit -m "BE: mb-safe canonical names in teammate crawl dispatch"
```

---

### Task 4: `SyncCharacterData` write-site canonicalization (defense in depth)

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php:287-295` (the `Character::updateOrCreate` call)
- Test: `tests/Unit/Blizzard/Jobs/SyncCharacterDataTeammateCrawlTest.php` or a targeted addition to whichever existing test drives `handle()` (see `tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php` / `tests/Unit/Blizzard/Jobs/SyncSlicePersistenceTest.php` for the established `BlizzardProfileClient` mocking pattern)

**Why:** queued jobs serialized *before* this deploy still carry raw-cased names, and `unserialize` bypasses constructors — canonicalizing the `updateOrCreate` keys is the one chokepoint that guarantees no new duplicate row can ever be written, regardless of dispatch path or payload age.

**Interfaces:**
- Produces: every persisted `characters` row has `name = BlizzardIdentity::name(...)`, `realm = BlizzardIdentity::realm(...)`. `display_name` continues to carry `$profile->name` (raw Blizzard casing) via the existing `$characterData` mapping — do not touch it.

- [ ] **Step 1: Write the failing test**

Read `tests/Unit/Blizzard/Jobs/SyncSlicePersistenceTest.php` first and reuse its client-mocking/fixture helper to run `handle()`. The test to add (place it in the same file as a sibling, or a new `SyncCharacterDataCanonicalNameTest.php` reusing the same setUp):

```php
public function test_capitalized_dispatch_upserts_into_canonical_row(): void
{
    $existing = Character::factory()->create([
        'name' => 'бробабади',
        'realm' => 'howling-fjord',
        'region' => 'eu',
        'game_version' => 'retail',
    ]);

    // Drive handle() with a job constructed with the DISPLAY-CASED name,
    // using this file's existing mocked-client fixture (profile response can
    // be the standard fixture; its ['name'] field should be 'Бробабади').
    $this->runSyncJob(region: 'eu', realm: 'howling-fjord', name: 'Бробабади');

    $this->assertSame(1, Character::query()
        ->where('realm', 'howling-fjord')->where('region', 'eu')->count());
    $this->assertSame('бробабади', $existing->fresh()->name);
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — count is 2 (a second row named `Бробабади` was created).

- [ ] **Step 3: Fix the write site**

In `app/Blizzard/Jobs/SyncCharacterData.php` (~line 287), change the `updateOrCreate` identity keys:

```php
$character = Character::updateOrCreate(
    [
        'name' => BlizzardIdentity::name($this->name),
        'realm' => BlizzardIdentity::realm($this->realm),
        'region' => $this->region,
        'game_version' => 'retail',
    ],
    $characterData,
);
```

(`use App\Support\BlizzardIdentity;` was already added in Task 3.)

- [ ] **Step 4: Run test to verify it passes**

Run the touched test file. Expected: PASS. Also run `./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/` — the routing/uniqueId/not-found tests must stay green.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Blizzard/Jobs/SyncCharacterData.php tests/Unit/Blizzard/Jobs/
git add -A && git commit -m "BE: canonicalize identity at Character upsert chokepoint"
```

---

### Task 5: `MythicPlusRatingMapper` — mb-safe member matching

**Files:**
- Modify: `app/Blizzard/Mappers/MythicPlusRatingMapper.php:53,64`
- Test: `tests/Unit/Blizzard/Mappers/MythicPlusRatingMapperTest.php` (create)

**Interfaces:**
- Consumes: `map(?array $base, ?array $season, string $characterName, string $characterRealm): CharacterMythicPlusRating` — signature unchanged.
- Produces: `->perSpec` now resolves when the wanted name is canonical-lowercase and the API member name is display-cased (the prod-verified `by_spec = null` bug).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\MythicPlusRatingMapper;
use Tests\TestCase;

final class MythicPlusRatingMapperTest extends TestCase
{
    private function seasonPayload(string $memberName): array
    {
        return [
            'best_runs' => [
                [
                    'mythic_rating' => ['rating' => 549.4],
                    'members' => [
                        [
                            'character' => [
                                'name' => $memberName,
                                'realm' => ['slug' => 'howling-fjord'],
                            ],
                            'specialization' => ['id' => 252],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_display_cased_cyrillic_member_matches_canonical_want_name(): void
    {
        $result = (new MythicPlusRatingMapper)->map(
            null,
            $this->seasonPayload('Бробабади'),
            'бробабади',
            'howling-fjord',
        );

        $this->assertSame([252 => 549], $result->perSpec);
    }

    public function test_ascii_mixed_case_member_still_matches(): void
    {
        $result = (new MythicPlusRatingMapper)->map(
            null,
            $this->seasonPayload('Melaniya'),
            'melaniya',
            'howling-fjord',
        );

        $this->assertSame([252 => 549], $result->perSpec);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/MythicPlusRatingMapperTest.php`
Expected: Cyrillic test FAILS (`perSpec` is `[]`), ASCII test passes.

- [ ] **Step 3: Fix the mapper**

In `app/Blizzard/Mappers/MythicPlusRatingMapper.php`, add `use App\Support\BlizzardIdentity;` and change `aggregatePerSpec()`:

```php
// before
$wantName = strtolower($name);
// after
$wantName = BlizzardIdentity::name($name);

// before
$memberName = strtolower((string) ($m['character']['name'] ?? ''));
// after
$memberName = BlizzardIdentity::name((string) ($m['character']['name'] ?? ''));
```

Leave the two realm lines as `strtolower` (realm slugs are ASCII per `BlizzardIdentity`'s docblock) but add a trailing comment `// realm slugs are ASCII`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/MythicPlusRatingMapperTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Blizzard/Mappers/MythicPlusRatingMapper.php tests/Unit/Blizzard/Mappers/MythicPlusRatingMapperTest.php
git add -A && git commit -m "BE: mb-safe member matching in MythicPlusRatingMapper"
```

---

### Task 6: mb-safe search needles

**Files:**
- Modify: `app/Models/Character.php:262-265` (`scopeNameSearch`)
- Modify: `app/Models/Guild.php:117-120` (`scopeNameSearch`)
- Modify: `app/Http/Controllers/GuildController.php:75` (roster member filter)
- Test: extend whichever existing feature test covers name suggestions (search `tests/` for `nameSearch` / `suggest` usages first; if none exists for Cyrillic, add cases to the closest existing suggestion/search test file)

**Interfaces:**
- Produces: searching `Бробабади` (any casing) matches canonical-lowercase rows; a single Cyrillic character no longer passes the 2-char minimum.

- [ ] **Step 1: Write the failing test**

Add to the existing suggestion/search feature test (adapt file/route to what exists — find it with `rg -l "nameSearch" tests/`):

```php
public function test_cyrillic_search_is_case_insensitive(): void
{
    Character::factory()->create([
        'name' => 'бробабади',
        'realm' => 'howling-fjord',
        'region' => 'eu',
        'game_version' => 'retail',
    ]);

    $hits = Character::query()->nameSearch('Бробабади')->get();

    $this->assertCount(1, $hits);
}

public function test_single_cyrillic_char_needle_is_rejected(): void
{
    Character::factory()->create(['name' => 'бробабади']);

    $this->assertCount(0, Character::query()->nameSearch('б')->get());
}
```

(Scope-level assertions are enough; SQLite `LIKE` on two already-lowercase operands is exact-match-correct for this fixture.)

- [ ] **Step 2: Run test to verify it fails**

Expected: first test FAILS (needle stays `Бробабади`); second FAILS (2 bytes pass `strlen < 2`).

- [ ] **Step 3: Fix the three call sites**

`app/Models/Character.php` `scopeNameSearch` (add `use Illuminate\Support\Str;` if absent):

```php
// before
$needle = strtolower(trim($q));

if (strlen($needle) < 2) {
// after
$needle = Str::lower(trim($q));

if (mb_strlen($needle) < 2) {
```

`app/Models/Guild.php` `scopeNameSearch`: identical change.

`app/Http/Controllers/GuildController.php:75` (add `use Illuminate\Support\Str;` if absent):

```php
// before
$query->where('name', 'LIKE', '%'.strtolower($filter).'%');
// after
$query->where('name', 'LIKE', '%'.Str::lower($filter).'%');
```

- [ ] **Step 4: Run tests to verify they pass**

Run the touched test file(s) plus `./vendor/bin/phpunit --filter=NameSearch` (or the closest existing filter) to confirm no regressions in ASCII search behavior.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Models/Character.php app/Models/Guild.php app/Http/Controllers/GuildController.php
git add -A && git commit -m "BE: mb-safe search needles (Cyrillic name search)"
```

---

### Task 7: `characters:canonicalize-names` repair command

**Files:**
- Create: `app/Console/Commands/CanonicalizeCharacterNames.php`
- Test: `tests/Feature/Console/CanonicalizeCharacterNamesTest.php` (create; `tests/Feature/Console/` already exists)

**Interfaces:**
- Consumes: `BlizzardIdentity::name()`; prod schema facts baked in below (verified 2026-07-25):
  - `characters` unique on `(name, realm, region, game_version)`; merge-relevant columns: `user_id`, `guild_id`, `display_name`, `num_of_searches`, `last_searched_at`, `mythic_plus_rating_by_spec`.
  - FK tables referencing `characters.id`: `dungeon_run_members` (no unique key involving `character_id` → conflict-free repoint; 0 mixed-case same-run rows in prod), `guild_members` (unique `(guild_id, name, realm)` — also no `character_id` key), and 7 slice tables unique on `(character_id, <entity cols>)`: `character_achievements(achievement_id)`, `character_mounts(mount_id)`, `character_pets(pet_id)`, `character_toys(toy_id)`, `character_professions(profession_id, tier_name)`, `character_pvp_brackets(bracket)`, `raid_encounter_kills(encounter_id, difficulty)`.
- Produces: `php artisan characters:canonicalize-names {--dry-run}` — idempotent, safe to re-run.

**Behavior spec:**

1. **Characters pass** — stream `characters` with `chunkById`; for each row whose `name !== BlizzardIdentity::name(name)`:
   - If a canonical-named row for the same `(realm, region, game_version)` exists → **merge** the non-canonical loser into that keeper (per-pair `DB::transaction`):
     - Repoint `dungeon_run_members.character_id` and `guild_members.character_id` with plain `UPDATE` (no unique conflicts possible).
     - For each of the 7 slice tables: repoint with a portable correlated `NOT EXISTS` guard, then delete leftovers (leftovers are re-syncable slice data — the keeper's next Full/StaleOnly sync rebuilds them; this is the only sanctioned deletion):
       ```sql
       UPDATE character_mounts SET character_id = :keeper
       WHERE character_id = :loser
         AND NOT EXISTS (
           SELECT 1 FROM character_mounts k
           WHERE k.character_id = :keeper AND k.mount_id = character_mounts.mount_id
         );
       DELETE FROM character_mounts WHERE character_id = :loser;
       ```
       (Inner table aliased `k`, outer referenced unaliased — required for SQLite, valid on PG.)
     - Scalar merge onto keeper: `num_of_searches` summed; `last_searched_at` = max non-null; `display_name ??= loser->display_name ?? loser->name` (the loser's capitalized name IS the display casing); `mythic_plus_rating_by_spec ??=` loser's; `user_id ??=` loser's; `guild_id ??=` loser's. Save keeper, delete loser row.
   - Else → **rename**: `name = canonical`, `display_name = display_name ?? old name`.
   - A group with two non-canonical casings and no canonical row resolves naturally: the first (lowest id) gets renamed, the second then finds it as keeper and merges.
2. **Guild-members pass** — stream `guild_members` similarly; for each non-canonical `name`:
   - If a sibling `(guild_id, canonical name, realm)` row exists → delete the non-canonical row (it is a stale duplicate the fixed roster sync's delete-missing would prune anyway; prefer keeping the sibling regardless of which has `character_id` — relink below fills gaps).
   - Else → rename with `display_name = display_name ?? old name`.
3. **Relink pass** — one portable statement backfilling `guild_members.character_id` for all NULL rows (fixes the 74.5k never-linked rows):
   ```sql
   UPDATE guild_members SET character_id = (
       SELECT c.id FROM characters c
       WHERE c.name = guild_members.name
         AND c.realm = guild_members.realm
         AND c.game_version = 'retail'
         AND c.region = (SELECT g.region FROM guilds g WHERE g.id = guild_members.guild_id)
   )
   WHERE character_id IS NULL
     AND EXISTS (
       SELECT 1 FROM characters c
       WHERE c.name = guild_members.name
         AND c.realm = guild_members.realm
         AND c.game_version = 'retail'
         AND c.region = (SELECT g.region FROM guilds g WHERE g.id = guild_members.guild_id)
     );
   ```
   (Scalar subquery is safe: `characters` unique index guarantees ≤1 match.)
4. `--dry-run`: perform no writes; report would-be rename/merge/delete/relink counts. Note in the command docblock: dry-run counts a two-loser group as 2 renames (the keeper-lookup can't see the not-yet-performed rename); real-run counts differ slightly — expected.
5. Output a summary line with all counters. Do **not** warm caches inside the command (the runbook does it explicitly).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\DungeonRunMember;
use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CanonicalizeCharacterNamesTest extends TestCase
{
    use RefreshDatabase;

    private function makePair(): array
    {
        $keeper = Character::factory()->create([
            'name' => 'бробабади', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail', 'num_of_searches' => 2,
            'mythic_plus_rating_by_spec' => null, 'display_name' => null,
        ]);
        $loser = Character::factory()->create([
            'name' => 'Бробабади', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail', 'num_of_searches' => 1,
            'mythic_plus_rating_by_spec' => [252 => 549], 'display_name' => null,
        ]);

        return [$keeper, $loser];
    }

    public function test_merges_case_duplicate_into_canonical_keeper(): void
    {
        [$keeper, $loser] = $this->makePair();

        $run = DungeonRun::factory()->create();
        DungeonRunMember::create([
            'dungeon_run_id' => $run->id, 'character_id' => $loser->id,
            'character_name' => 'Бробабади', 'character_realm' => 'howling-fjord',
            'character_region' => 'eu',
        ]);
        // Conflicting + non-conflicting slice rows.
        DB::table('character_mounts')->insert([
            ['character_id' => $keeper->id, 'mount_id' => 1],
            ['character_id' => $loser->id, 'mount_id' => 1],  // conflict → dropped
            ['character_id' => $loser->id, 'mount_id' => 2],  // moves to keeper
        ]);

        $this->artisan('characters:canonicalize-names')->assertSuccessful();

        $this->assertNull(Character::find($loser->id));
        $keeper->refresh();
        $this->assertSame(3, $keeper->num_of_searches);
        $this->assertSame('Бробабади', $keeper->display_name);
        $this->assertNotNull($keeper->mythic_plus_rating_by_spec);
        $this->assertSame($keeper->id, DungeonRunMember::query()->firstOrFail()->character_id);
        $this->assertEqualsCanonicalizing(
            [1, 2],
            DB::table('character_mounts')->where('character_id', $keeper->id)->pluck('mount_id')->all(),
        );
        $this->assertSame(0, DB::table('character_mounts')->where('character_id', $loser->id)->count());
    }

    public function test_renames_lone_non_canonical_row_and_preserves_display_casing(): void
    {
        $char = Character::factory()->create([
            'name' => 'Девоуреркала', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail', 'display_name' => null,
        ]);

        $this->artisan('characters:canonicalize-names')->assertSuccessful();

        $char->refresh();
        $this->assertSame('девоуреркала', $char->name);
        $this->assertSame('Девоуреркала', $char->display_name);
    }

    public function test_guild_members_renamed_deduped_and_relinked(): void
    {
        $guild = Guild::factory()->create(['region' => 'eu']);
        $char = Character::factory()->create([
            'name' => 'бробабади', 'realm' => 'howling-fjord', 'region' => 'eu',
            'game_version' => 'retail',
        ]);

        $lone = GuildMember::factory()->create([
            'guild_id' => $guild->id, 'name' => 'Бробабади',
            'realm' => 'howling-fjord', 'character_id' => null,
        ]);
        // Sibling pair in a second guild: canonical row already exists → cap row deleted.
        $guild2 = Guild::factory()->create(['region' => 'eu']);
        GuildMember::factory()->create([
            'guild_id' => $guild2->id, 'name' => 'девоуреркала', 'realm' => 'howling-fjord',
        ]);
        $dupe = GuildMember::factory()->create([
            'guild_id' => $guild2->id, 'name' => 'Девоуреркала', 'realm' => 'howling-fjord',
        ]);

        $this->artisan('characters:canonicalize-names')->assertSuccessful();

        $lone->refresh();
        $this->assertSame('бробабади', $lone->name);
        $this->assertSame('Бробабади', $lone->display_name);
        $this->assertSame($char->id, $lone->character_id);   // relinked
        $this->assertNull(GuildMember::find($dupe->id));      // deduped
    }

    public function test_dry_run_changes_nothing(): void
    {
        [, $loser] = $this->makePair();

        $this->artisan('characters:canonicalize-names --dry-run')->assertSuccessful();

        $this->assertNotNull(Character::find($loser->id));
        $this->assertSame('Бробабади', $loser->fresh()->name);
    }
}
```

Adjust factory field lists to what `CharacterFactory` / `GuildMemberFactory` actually provide (both exist in `database/factories/`; `GuildMemberFactory` already lowercases fake names). If `character_mounts` needs timestamps or extra NOT NULL columns, include them in the inserts.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Console/CanonicalizeCharacterNamesTest.php`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/CanonicalizeCharacterNames.php` following the behavior spec above. Skeleton:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use App\Support\BlizzardIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CanonicalizeCharacterNames extends Command
{
    protected $signature = 'characters:canonicalize-names {--dry-run : Report what would change without writing}';

    protected $description = 'One-off repair: mb-lowercase character/guild-member names, merge case-duplicate character rows, relink guild members (2026-07 strtolower regression)';

    /** unique key columns per slice table (besides character_id) */
    private const SLICE_TABLES = [
        'character_achievements' => ['achievement_id'],
        'character_mounts' => ['mount_id'],
        'character_pets' => ['pet_id'],
        'character_toys' => ['toy_id'],
        'character_professions' => ['profession_id', 'tier_name'],
        'character_pvp_brackets' => ['bracket'],
        'raid_encounter_kills' => ['encounter_id', 'difficulty'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        // ... three passes per behavior spec, each maintaining counters ...
        // characters pass: Character::query()->select([...merge columns...])
        //   ->chunkById(1000, fn ($chunk) => ...);
        // per non-canonical row: DB::transaction(fn () => merge-or-rename)
        // guild_members pass: DB::table('guild_members')->...->chunkById(1000, ...)
        // relink pass: DB::update(<<<'SQL' ... SQL) unless dry-run
        // summary: $this->info("characters: {$renamed} renamed, {$merged} merged; guild_members: {$gmRenamed} renamed, {$gmDeleted} deleted, {$relinked} relinked");
        return self::SUCCESS;
    }
}
```

Implementation notes (bake these in — they are correctness constraints, not suggestions):
- All canonical computations in PHP via `BlizzardIdentity::name()` — never SQL `lower()`.
- Merge/rename each row inside `DB::transaction`.
- `chunkById` pages on `id`, so renames/deletes inside the pass don't disturb iteration.
- Slice repoint SQL exactly as in the behavior spec (unaliased outer table).
- `last_searched_at` max: `collect([$keeper->last_searched_at, $loser->last_searched_at])->filter()->max()`.
- Dry-run performs zero writes (guard every write, including the relink `DB::update`; report the relink candidate count via the `EXISTS` sub-condition as a `->count()` instead).

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/Console/CanonicalizeCharacterNamesTest.php`
Expected: PASS (all 4 tests).

- [ ] **Step 5: Full suite + pint + commit**

```bash
composer test          # full suite — this task touches shared tables
./vendor/bin/pint app/Console/Commands/CanonicalizeCharacterNames.php tests/Feature/Console/CanonicalizeCharacterNamesTest.php
git add -A && git commit -m "BE: characters:canonicalize-names repair command"
```

---

### Task 8: Document the invariant + deploy & repair runbook

**Files:**
- Modify: `CLAUDE.md` (backend — the `## Conventions` section)

- [ ] **Step 1: Document the convention**

Add one bullet to backend `CLAUDE.md` → `## Conventions`:

```markdown
- Character/guild-member names are canonical **mb-lowercase** (`BlizzardIdentity::name()`); display casing lives in `display_name`. NEVER `strtolower()` a name — it is ASCII-only and mints case-duplicate rows for Cyrillic/accented names (2026-07-25 incident; repair: `characters:canonicalize-names --dry-run`).
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md && git commit -m "BE: document mb-lowercase name invariant"
```

- [ ] **Step 3: Deploy + run the repair (requires the human's go-ahead for the non-dry-run step)**

```bash
# build + roll the three BE containers (shared image, 3 tags — see deploy gotchas memory)
cd /srv/dakis
docker compose build guild-service-v2-app guild-service-v2-horizon guild-service-v2-scheduler
docker compose up -d guild-service-v2-app guild-service-v2-horizon guild-service-v2-scheduler

# quiesce writers so a concurrent sync can't race the merge
docker compose stop guild-service-v2-horizon guild-service-v2-scheduler

docker compose exec guild-service-v2-app php artisan characters:canonicalize-names --dry-run
# EXPECTED ORDER OF MAGNITUDE (prod, 2026-07-25): ~55k character renames incl. ~28 merges,
# ~74.5k guild_members fixes. Wildly different numbers → STOP and investigate.

# >>> PAUSE HERE: show the dry-run output to the user and get explicit confirmation <<<
docker compose exec guild-service-v2-app php artisan characters:canonicalize-names

docker compose start guild-service-v2-horizon guild-service-v2-scheduler

# rebuild the stats cache inline (~18s) and verify
docker compose exec guild-service-v2-app php artisan tinker --execute='App\Jobs\WarmCharacterStats::dispatchSync();'
curl -s http://localhost:8091/api/v1/stats/characters | python3 -c "import json,sys; print(json.dumps(json.load(sys.stdin)['top_performers']['item_level'], ensure_ascii=False, indent=1))"
```

- [ ] **Step 4: Verify success criteria**

```bash
docker exec guild-service-v2-postgres psql -U guild_service -d guild_service \
  -c "SELECT count(*) FROM characters WHERE name <> lower(name);" \
  -c "SELECT count(*) FROM (SELECT lower(name), realm, region FROM characters WHERE game_version='retail' GROUP BY 1,2,3 HAVING count(*)>1) t;" \
  -c "SELECT count(*) FROM guild_members WHERE name <> lower(name);"
```

All three counts must be 0 (PG's `lower()` is mb-correct on this DB — verified), and the `item_level` top-performers payload must contain no case-duplicate identities. Check the UI top item level view shows 5 distinct characters.

- [ ] **Step 5: Push subtree split**

```bash
cd /home/dakiman/dev/guild-service-v2
git subtree split --prefix=backend -b be-split && git push be be-split:master && git branch -D be-split
```

---

## Risks & mitigations (reviewed 2026-07-25)

| Risk | Mitigation |
|---|---|
| Queued jobs serialized pre-deploy carry raw-cased names (ctors don't run on unserialize) | Task 4's write-site canonicalization catches them; repair runs after deploy |
| Concurrent sync re-creates a row mid-repair | Horizon + scheduler stopped during the repair run; command is idempotent — re-run if in doubt |
| Slice-table unique conflicts during merge | `NOT EXISTS`-guarded repoint, leftovers deleted (slices fully re-sync by design; keeper's data preferred) |
| Same run holding both casings of one player → double seat after repoint | Verified 0 such rows in prod; `dungeon_run_members` has no `character_id` unique key, so repoint cannot error either way |
| SQLite tests vs PG behavior | All casing in PHP; repair SQL uses portable correlated subqueries (no UPDATE-target alias) |
| Losing the display casing when lowercasing rows | `display_name ??= old capitalized name` on every rename/merge |
| FE shows lowercase names | FE already renders via `capitalizeName()` (Unicode-aware JS) everywhere top performers/runs/rosters appear — no FE change |
| `mb_strlen` gate change rejects 1-Cyrillic-char searches that previously (incorrectly) ran | Intended fix, matches ASCII behavior |
| `season_archives` snapshots retain old casing | Immutable by design; FE `capitalizeName()` renders them fine — leave |
| Stats cache still shows duplicates post-repair | Runbook re-warms via `WarmCharacterStats::dispatchSync()` and verifies the payload |
