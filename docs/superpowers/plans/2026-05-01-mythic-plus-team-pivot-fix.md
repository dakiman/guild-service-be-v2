# Mythic+ Team Pivot Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the Mythic+ slice sync so that (a) all real party members of a run land on the `dungeon_run_members` pivot table — not just the last one — and (b) two characters who shared the same M+ run can each complete `syncMythicPlus()` without colliding on the unique constraint and silently aborting their slice update.

**Bug summary (already root-caused — do not re-investigate):** `SyncCharacterData::syncMythicPlus()` (lines 243–308) iterates `$run->team` and calls `$dungeonRun->members()->syncWithoutDetaching([$memberCharacter?->id ?? $character->id => [...]])`. The `?? $character->id` fallback exists because `dungeon_run_members.character_id` is keyed off Eloquent's `BelongsToMany` pivot key, but the pivot's actual unique constraint is on `(dungeon_run_id, character_name, character_realm, character_region)` — a different key shape entirely. Two failure modes drop out of that mismatch:

1. **Within-sync data loss (silent):** If a single run has multiple unknown party members, all of them resolve to the same fallback id (`$character->id`). `syncWithoutDetaching` keys on the pivot's `character_id`, so they all UPDATE-overwrite the same row. Only the last unknown member persists. User sees 1–2 teammates per run instead of 4–5.

2. **Cross-character collision (loud):** If two synced characters share a run with an unknown member, the second character's sync attempts to upsert that unknown member's `(name, realm, region)` under its own fallback `character_id`, but the first character has already written a row keyed on the same `(name, realm, region)` under its own fallback id. The unique constraint `uq_dungeon_run_member` rejects it as a `SQLSTATE[23505]` duplicate. The slice's broad `try/catch` swallows the error, `mythics_synced_at` is never written, and every page load thereafter re-dispatches a Full sync that fails identically.

**Approach:** Stop driving this pivot through `BelongsToMany::syncWithoutDetaching`. Use `DB::table('dungeon_run_members')->updateOrInsert()` keyed on the actual unique tuple `(dungeon_run_id, character_name, character_realm, character_region)`. Set `character_id = $memberCharacter?->id` (NULL when unknown — the column already permits null, and the FK is `nullOnDelete`). Drop the `?? $character->id` fallback entirely. Wrap the per-run pivot writes in `DB::transaction` to match other Plan-2/Plan-4 slice conventions, and add delete-missing semantics within the run so stale members from re-runs disappear.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL (production), SQLite in-memory (tests).

**Out of scope:**
- Schema changes. The unique constraint is correct as written; do not migrate it. (See Open Questions if you find a strong counter-argument.)
- The other eight slices in `SyncCharacterData::handle()`. They are unaffected.
- Any FE work. The FE already reads `members[]` as-is; once the BE persists the right rows, the FE renders correctly without changes.

**Sequencing:** Standalone bugfix. Branch off `master`. No dependencies on in-flight feature branches.

**Deploy-ready at the end of:** this plan, after running the backfill artisan command in each environment and restarting Horizon (`docker compose restart horizon`).

---

## Task 1: Create the bugfix branch

**Files:** none (git only)

- [ ] **Step 1:** From a clean working tree:
  ```bash
  cd backend
  git status --short
  git checkout master
  git pull
  git checkout -b fix/mythic-plus-team-pivot
  ```

- [ ] **Step 2:** Confirm the buggy code is still present at the expected line range:
  ```bash
  grep -n "syncWithoutDetaching" app/Blizzard/Jobs/SyncCharacterData.php
  ```
  Expected: a single hit inside `syncMythicPlus()` around line 284.

---

## Task 2: Failing test — within-sync data loss (single sync, multiple unknown members)

**Files:**
- Create: `backend/tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php`

This is the TDD entry point. The test must fail against `master` and pass after Task 5.

- [ ] **Step 1: Write the test class skeleton**

Create `backend/tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php` with:

- `use RefreshDatabase`
- A helper that constructs a `MythicPlusRun` DTO (or whatever shape `MythicPlusMapper::map()` returns — read the mapper to confirm) with a configurable number of unknown team members. Bypass the HTTP layer entirely: instantiate `SyncCharacterData` and reach the pivot-write loop by either (a) extracting the team-write loop into a private method we can call directly via reflection, OR (b) preferred: re-shape the test to construct a `DungeonRun` model + a synthetic `$run->team` array directly, and exercise the new helper introduced in Task 5. Pick (b) — it avoids reflection and matches the slice extraction other plans use.

  Concretely: Task 5 will introduce `private function persistRunTeam(DungeonRun $run, array $team, Character $syncingCharacter): void`. Test against that helper.

- [ ] **Step 2: First test — within-sync, four unknown members all persist**

```php
public function test_persists_all_unknown_members_when_run_has_no_db_matches(): void
{
    $synced = Character::factory()->create([
        'name' => 'syncedchar', 'realm' => 'silvermoon', 'region' => 'eu',
    ]);
    $run = DungeonRun::factory()->create();

    $team = [
        ['name' => 'Alpha',   'realm' => 'silvermoon', 'specialization' => 'Frost', 'equipped_item_level' => 640],
        ['name' => 'Beta',    'realm' => 'silvermoon', 'specialization' => 'Fire',  'equipped_item_level' => 642],
        ['name' => 'Gamma',   'realm' => 'silvermoon', 'specialization' => 'Arcane','equipped_item_level' => 638],
        ['name' => 'Delta',   'realm' => 'silvermoon', 'specialization' => 'Frost', 'equipped_item_level' => 641],
    ];

    // Invoke whichever entry point Task 5 settles on. If the helper is private,
    // wrap it in a thin public test seam or call via reflection — pick the
    // pattern the rest of the suite uses.
    app(SyncCharacterData::class, [
        'region' => 'eu', 'realm' => 'silvermoon', 'name' => 'syncedchar',
        'depth' => SyncDepth::Full,
    ])->persistRunTeamForTesting($run, $team, $synced);

    $rows = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->get();
    $this->assertCount(4, $rows, 'All four unknown members must persist as distinct pivot rows');
    $this->assertEqualsCanonicalizing(
        ['Alpha', 'Beta', 'Gamma', 'Delta'],
        $rows->pluck('character_name')->all(),
    );
    $this->assertTrue($rows->every(fn ($r) => $r->character_id === null),
        'Unknown members must persist with character_id = NULL, not the syncing character\'s id');
}
```

(`persistRunTeamForTesting` is a thin public seam wrapping the new private helper — add it in Task 5 as a `// @internal` method, gated to non-production via comment, OR drop the `ForTesting` suffix and just make the helper itself public on the job class. Pick whichever your codebase tolerates — same call shape either way.)

- [ ] **Step 3: Run and confirm red**

```bash
./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php --filter=test_persists_all_unknown_members_when_run_has_no_db_matches
```

Expected: fails. (Helper does not exist yet, OR if you implement the bug-faithful version against current code first via reflection, only 1 row persists.)

- [ ] **Step 4: Commit the failing test**

```bash
git add tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
git commit -m "test(mythic-plus): add failing test for within-sync data loss on unknown members"
```

---

## Task 3: Failing test — cross-character collision

**Files:**
- Modify: `backend/tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php`

Append a second test reproducing the `23505` collision. The test simulates two characters sequentially writing the same run's team, where the team includes an unknown member.

- [ ] **Step 1: Append the test**

```php
public function test_two_characters_sharing_a_run_with_an_unknown_member_both_succeed(): void
{
    $charA = Character::factory()->create(['name' => 'saiyanin',  'realm' => 'silvermoon', 'region' => 'eu']);
    $charB = Character::factory()->create(['name' => 'melaniya',  'realm' => 'silvermoon', 'region' => 'eu']);
    $run   = DungeonRun::factory()->create();

    $team = [
        ['name' => 'Saiyanin', 'realm' => 'silvermoon',     'specialization' => 'Frost',     'equipped_item_level' => 640],
        ['name' => 'Melaniya', 'realm' => 'silvermoon',     'specialization' => 'Holy',      'equipped_item_level' => 638],
        ['name' => 'Melodud',  'realm' => 'twisting-nether','specialization' => 'Affliction','equipped_item_level' => 635],
    ];

    $job = fn (Character $c) => app(SyncCharacterData::class, [
        'region' => 'eu', 'realm' => $c->realm, 'name' => $c->name, 'depth' => SyncDepth::Full,
    ]);

    $job($charA)->persistRunTeamForTesting($run, $team, $charA);
    $job($charB)->persistRunTeamForTesting($run, $team, $charB);

    $rows = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->get();
    $this->assertCount(3, $rows, 'Three distinct (name,realm,region) rows expected');

    // Both real characters resolved to their character_id; the unknown stays null.
    $byName = $rows->keyBy('character_name');
    $this->assertSame($charA->id, $byName['Saiyanin']->character_id);
    $this->assertSame($charB->id, $byName['Melaniya']->character_id);
    $this->assertNull($byName['Melodud']->character_id);
}

public function test_known_member_resolves_case_insensitively(): void
{
    // The current code calls Character::where('name', strtolower($member['name'])).
    // Whatever resolution rule the fix preserves, lock it in here.
    $known = Character::factory()->create(['name' => 'thrall', 'realm' => 'orgrimmar', 'region' => 'us']);
    $synced = Character::factory()->create(['name' => 'jaina', 'realm' => 'theramore', 'region' => 'us']);
    $run = DungeonRun::factory()->create();

    $team = [
        ['name' => 'Thrall', 'realm' => 'orgrimmar', 'specialization' => 'Enhancement', 'equipped_item_level' => 645],
    ];

    app(SyncCharacterData::class, [
        'region' => 'us', 'realm' => 'theramore', 'name' => 'jaina', 'depth' => SyncDepth::Full,
    ])->persistRunTeamForTesting($run, $team, $synced);

    $row = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
    $this->assertSame($known->id, $row->character_id);
}
```

- [ ] **Step 2: Run, confirm both new tests are red**

Expected: collision test would also fail today (would throw `QueryException` or, after the broad `try/catch` is introduced into the helper, would silently leave fewer rows than asserted).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
git commit -m "test(mythic-plus): cover cross-character collision and known-member resolution"
```

---

## Task 4: Failing test — `mythics_synced_at` advances on both characters

**Files:**
- Modify: `backend/tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php`

A separate, higher-level test that exercises the full `syncMythicPlus()` method end-to-end via faked Blizzard responses, asserting `mythics_synced_at` is set on both shared-run characters.

- [ ] **Step 1: Add a fake-mode test** that:
  - Uses `Http::fake(...)` to stub `BlizzardProfileClient::getCharacterMythicPlusPool` and `BlizzardGameDataClient::getCurrentMythicPlusSeason` (likely simpler to mock the clients via container binding — read the existing `SyncCharacterDataNotFoundTest` for the pattern this codebase already uses).
  - Drives two `SyncCharacterData::dispatchSync()` (or `->handle()` directly with injected mappers) for two characters whose Blizzard responses each contain one shared run with an unknown teammate.
  - Asserts both `Character::find($charA->id)->mythics_synced_at` and `…($charB->id)->mythics_synced_at` are non-null after the second job completes.

If wiring this end-to-end is too heavy, downgrade Task 4 to a focused regression at the helper level: assert that the helper does not throw on the second character's call, and that both characters' `mythics_synced_at` get updated by the surrounding `syncMythicPlus()` (call `syncMythicPlus` directly via reflection or via promoting it to package-private).

Decide based on what `SyncCharacterDataNotFoundTest` already does — match its mocking strategy.

- [ ] **Step 2: Confirm red, commit**

```bash
./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
git add tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
git commit -m "test(mythic-plus): assert mythics_synced_at advances on both shared-run characters"
```

---

## Task 5: The fix — replace `syncWithoutDetaching` with direct `updateOrInsert`

**Files:**
- Modify: `backend/app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Extract the team-write loop**

Inside `SyncCharacterData`, add a new private helper:

```php
/**
 * Persist this run's team roster onto the dungeon_run_members pivot.
 *
 * Bypasses BelongsToMany::syncWithoutDetaching deliberately: the pivot's
 * unique key is (dungeon_run_id, character_name, character_realm, character_region),
 * not (dungeon_run_id, character_id), so Eloquent's character_id-keyed sync
 * upserts are the wrong shape and either silently overwrite multiple unknowns
 * onto a single row or hit SQLSTATE[23505] when two characters' syncs cross.
 *
 * @param  array<int, array{name: string, realm: string, specialization: ?string, equipped_item_level: ?int}>  $team
 */
private function persistRunTeam(DungeonRun $run, array $team): void
{
    $now = now();
    $keep = [];

    foreach ($team as $member) {
        $name   = $member['name'];
        $realm  = $member['realm'];
        $region = $this->region;

        // Resolve to a real character row when we have one. Stay NULL otherwise —
        // the column allows NULL and the FK is nullOnDelete; do not fall back to
        // the syncing character's id (that was the original bug).
        $resolvedId = Character::query()
            ->where('name', strtolower($name))
            ->where('realm', $realm)
            ->where('region', $region)
            ->where('game_version', 'retail')
            ->value('id');

        DB::table('dungeon_run_members')->updateOrInsert(
            [
                'dungeon_run_id'   => $run->id,
                'character_name'   => $name,
                'character_realm'  => $realm,
                'character_region' => $region,
            ],
            [
                'character_id'         => $resolvedId, // null when unknown
                'spec_name'            => $member['specialization'] ?? null,
                'equipped_item_level'  => $member['equipped_item_level'] ?? null,
                'updated_at'           => $now,
                // created_at: tolerate reset on re-sync; FE doesn't surface this column.
                // If preserving original created_at matters, switch to a SELECT-then-INSERT-or-UPDATE pattern.
                'created_at'           => $now,
            ],
        );

        $keep[] = ['name' => $name, 'realm' => $realm, 'region' => $region];
    }

    // Delete-missing within this run: drop pivot rows whose (name, realm, region)
    // is no longer in the latest team list. Matches the Plan-2 / Plan-4 slice
    // delete-missing convention. Use the "fetch-then-delete-by-id" form for
    // SQLite/PG portability — row-tuple WHERE clauses don't translate cleanly
    // across both drivers (see syncProfessions for the same pattern).
    $existing = DB::table('dungeon_run_members')
        ->where('dungeon_run_id', $run->id)
        ->get(['id', 'character_name', 'character_realm', 'character_region']);

    $keepKey = fn (string $n, string $r, string $reg) => "{$n}|{$r}|{$reg}";
    $keepSet = collect($keep)->mapWithKeys(fn ($k) =>
        [$keepKey($k['name'], $k['realm'], $k['region']) => true]
    )->all();

    $toDelete = $existing
        ->reject(fn ($row) => isset($keepSet[$keepKey($row->character_name, $row->character_realm, $row->character_region)]))
        ->pluck('id')
        ->all();

    if ($toDelete !== []) {
        DB::table('dungeon_run_members')->whereIn('id', $toDelete)->delete();
    }
}
```

**On `created_at`:** the simplest path is to let `updateOrInsert` pass `created_at = $now` and tolerate that re-syncs reset it. If preserving the original `created_at` matters (it currently does because `BelongsToMany::withTimestamps()` sets it once), do a two-step: `SELECT id FROM dungeon_run_members WHERE …unique key…` then `INSERT` with `created_at=$now` if no row, else `UPDATE` excluding `created_at`. The pragmatic tradeoff: the FE doesn't surface this column anywhere, so the simpler `updateOrInsert` form is acceptable.

- [ ] **Step 2: Wrap `syncMythicPlus` body in `DB::transaction`**

The current `syncMythicPlus()` does **not** use `DB::transaction`. Per backend/CLAUDE.md, all other Plan-2/Plan-4 slices wrap their writes in a single `DB::transaction` so partial-failure leaves the slice's `*_synced_at` unwritten and the pivot rows unwritten together. Match that convention. The new shape:

```php
private function syncMythicPlus(...): void
{
    if (! config('blizzard.sync.mythic_plus_enabled')) {
        return;
    }

    try {
        $season = $gameData->getCurrentMythicPlusSeason();
        ['base' => $base, 'season' => $seasonData] = $client->getCharacterMythicPlusPool(
            $this->realm, $this->name, $season,
        );
        $runs = $mapper->map($seasonData ?? [], $season);
        $rating = $ratingMapper->map($base, $seasonData, $this->name, $this->realm);

        DB::transaction(function () use ($runs, $rating, $character) {
            foreach ($runs as $run) {
                $dungeonRun = DungeonRun::updateOrCreate(
                    [
                        'season' => $run->season,
                        'dungeon_id' => $run->dungeonId,
                        'completed_timestamp' => $run->completedTimestamp,
                    ],
                    [
                        'dungeon_name' => $run->dungeonName,
                        'keystone_level' => $run->keystoneLevel,
                        'duration' => $run->duration,
                        'is_completed_on_time' => $run->isCompletedOnTime,
                        'affixes' => $run->affixes,
                    ],
                );

                $this->persistRunTeam($dungeonRun, $run->team);
            }

            $character->update([
                'mythic_plus_rating' => $rating->rating,
                'mythic_plus_rating_color' => $rating->color,
                'mythic_plus_rating_by_spec' => $rating->perSpec ?: null,
                'mythics_synced_at' => now(),
            ]);
        });
    } catch (Throwable $e) {
        Log::warning('Failed to sync mythic+ data for character', [
            'character' => "{$this->name}-{$this->realm}-{$this->region}",
            'error' => $e->getMessage(),
        ]);
    }
}
```

**Argument for the transaction wrap:** consistency with every other Plan-2/Plan-4 slice (see CLAUDE.md "Per-slice Full sync" bullet — *"Each slice wraps its own try/catch around a single `DB::transaction`"*). Without it, a partial failure mid-loop leaves some runs persisted with team rows but others unpersisted, and `mythics_synced_at` either gets written despite the partial failure (if outside the transaction) or never gets written even though some runs did persist (current behavior). Wrapping it produces the same all-or-nothing semantics every other slice gives.

**Tradeoff:** The transaction holds locks for the duration of all `updateOrInsert` calls (one per team member per run, so up to ~5 × ~60 = 300 row-level locks per character sync). That is well within PostgreSQL's comfort zone and matches the volume the achievements slice already commits in a single transaction.

- [ ] **Step 3: Add the test seam**

Either:
  - Make `persistRunTeam` `public` so the tests can call it directly (acceptable — the job class is a single-use unit and there is no public-API contract to protect), or
  - Add a thin public `persistRunTeamForTesting` shim that just forwards. Document with `// @internal — exposed for tests, do not call from production code.`

Pick the public-method route to keep the test calls clean.

- [ ] **Step 4: Run the new tests, confirm green**

```bash
./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
```

Expected: all four tests pass.

- [ ] **Step 5: Run the full Blizzard test subset**

```bash
./vendor/bin/phpunit tests/Unit/Blizzard tests/Feature/Blizzard
```

Expected: green — the change is purely behavioral inside `syncMythicPlus`.

- [ ] **Step 6: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "fix(mythic-plus): write dungeon_run_members via DB::updateOrInsert keyed on the unique tuple"
```

---

## Task 6: Backfill Artisan command — repair stale fallback rows

**Files:**
- Create: `backend/app/Console/Commands/RepairDungeonRunMemberCharacterIds.php`
- Create: `backend/tests/Feature/Console/RepairDungeonRunMemberCharacterIdsTest.php`

The pre-fix code wrote rows where `pivot.character_id = $character->id` (the *syncing* character) but `pivot.character_name / realm / region` referred to an *unknown* member. Those rows are now stale: the FK points to a character whose identity does not match the row's named identity.

After the fix, the next sync of any of those affected characters will leave the row in place but the row will never be re-keyed (the new code calls `updateOrInsert` on the unique tuple, so it would update the existing row in-place with the *correct* `character_id` only if the unknown member becomes known — otherwise the stale FK lingers).

The cleanup: for every `dungeon_run_members` row where `character_id IS NOT NULL` AND the linked character's `(name, realm, region)` does not equal the row's `(character_name, character_realm, character_region)`, set `character_id = NULL`. This is safe: the FK is `nullOnDelete`, the column is nullable, and the FE renders `member.character_name` directly without dereferencing the FK.

- [ ] **Step 1: Match the existing command convention**

Read `backend/app/Console/Commands/BackfillSlices.php` first to match style (signature, description, output, exit codes, chunked progress).

- [ ] **Step 2: Write the command**

Signature: `blizzard:repair-dungeon-run-member-character-ids {--dry-run}`.

Logic:
```php
$query = DB::table('dungeon_run_members as drm')
    ->join('characters as c', 'c.id', '=', 'drm.character_id')
    ->whereNotNull('drm.character_id')
    ->where(function ($q) {
        $q->whereColumn('c.name', '!=', DB::raw('LOWER(drm.character_name)'))
          ->orWhereColumn('c.realm', '!=', 'drm.character_realm')
          ->orWhereColumn('c.region', '!=', 'drm.character_region');
    })
    ->select('drm.id', 'drm.character_name', 'drm.character_realm', 'drm.character_region',
             'drm.character_id', 'c.name as linked_name', 'c.realm as linked_realm', 'c.region as linked_region');
```

Note the `LOWER(drm.character_name)` comparison: `Character::name` is stored lowercased (per CLAUDE.md and `Character::scopeByIdentity`), but `dungeon_run_members.character_name` carries the raw Blizzard-cased name (e.g., "Melodud"). So the match must be case-insensitive on the name half.

- Print a summary: `Found N stale rows. Sample: …`.
- If `--dry-run`, exit. Otherwise iterate in chunks of 1000 and `UPDATE … SET character_id = NULL` keyed by id.
- Print `Repaired N rows.` Exit 0.

- [ ] **Step 3: Write the command test**

`tests/Feature/Console/RepairDungeonRunMemberCharacterIdsTest.php` should:

- Seed three pivot rows: one matching, one mismatched name-only, one mismatched realm-only, one with `character_id = NULL` (untouched).
- Run `--dry-run`, assert no DB changes, assert command output mentions the count.
- Run for real, assert exactly the two mismatched rows have `character_id = NULL` and the matching/already-null rows are untouched.

- [ ] **Step 4: Run the test, confirm green, commit**

```bash
./vendor/bin/phpunit tests/Feature/Console/RepairDungeonRunMemberCharacterIdsTest.php
git add app/Console/Commands/RepairDungeonRunMemberCharacterIds.php tests/Feature/Console/RepairDungeonRunMemberCharacterIdsTest.php
git commit -m "feat(mythic-plus): add repair command to null out stale dungeon_run_members.character_id"
```

---

## Task 7: Run the full backend suite + Pint

**Files:** none (verification)

- [ ] **Step 1:**
  ```bash
  composer test
  ./vendor/bin/pint --test
  ```
  Expected: green. If Pint flags formatting, run `./vendor/bin/pint` and re-stage.

- [ ] **Step 2:** Commit any Pint fixes:
  ```bash
  git add -A
  git commit -m "style: pint after mythic-plus pivot fix"
  ```

---

## Task 8: Manual verification in dev

**Files:** none (manual)

- [ ] **Step 1: Apply the fix to the running stack**

```bash
docker compose restart horizon
```

Required because the Horizon container runs with `PHP_OPCACHE_VALIDATE_TIMESTAMPS=0` (per backend/CLAUDE.md), so a restart is needed for it to pick up the edited job class and the new helper method.

- [ ] **Step 2: Run the backfill against the dev DB**

```bash
docker compose exec app php artisan blizzard:repair-dungeon-run-member-character-ids --dry-run
```

Expected: prints the count of stale rows it would null-out. Example: "Found 12 stale rows. Sample: …".

Then, for real:

```bash
docker compose exec app php artisan blizzard:repair-dungeon-run-member-character-ids
```

Expected: "Repaired 12 rows."

- [ ] **Step 3: Verify the known bad row was repaired**

```bash
docker compose exec app php artisan tinker --execute="
use Illuminate\Support\Facades\DB;
dump(DB::table('dungeon_run_members')
    ->where('character_name', 'Melodud')
    ->where('character_realm', 'twisting-nether')
    ->get(['id', 'dungeon_run_id', 'character_id', 'character_name', 'character_realm']));
"
```

Expected: any rows for Melodud now have `character_id = null`. Before the fix it was 1 (saiyanin's id) or 2 (saiyanin's id and a second one with melaniya's id).

- [ ] **Step 4: Trigger a fresh Full sync of one of the previously-failing characters**

In the FE, hit a Mythic+-affected character (e.g., melaniya) with `?refresh=1` if available, or wait for staleness to trip the proactive sweep. Inspect Horizon dashboard for the `SyncCharacterData` job — it should complete green.

- [ ] **Step 5: Verify `mythics_synced_at` advances**

```bash
docker compose exec app php artisan tinker --execute="
use App\Models\Character;
foreach (['saiyanin', 'melaniya'] as \$n) {
    \$c = Character::where('name', \$n)->where('region', 'eu')->first();
    dump(\$n.': mythics_synced_at = '.(string) \$c?->mythics_synced_at);
}
"
```

Expected: both timestamps are recent (within the last few minutes).

- [ ] **Step 6: Verify all five teammates persisted on the affected run**

```bash
docker compose exec app php artisan tinker --execute="
use Illuminate\Support\Facades\DB;
dump(DB::table('dungeon_run_members')->where('dungeon_run_id', 9)->orderBy('character_name')->get([
  'character_id', 'character_name', 'character_realm', 'spec_name', 'equipped_item_level',
]));
"
```

Expected: 5 rows. Each member's `(character_name, character_realm)` is unique. Real characters have non-null `character_id`; unknown teammates have `character_id = NULL`. No row has the syncing character's id "borrowed" for a teammate.

- [ ] **Step 7: Tail the logs for any swallowed `23505` errors during a sweep**

```bash
docker compose exec app tail -f storage/logs/laravel.log | grep -E "uq_dungeon_run_member|SQLSTATE\[23505\]"
```

Expected: nothing on a fresh sweep. (Pre-fix, this would print a steady stream during proactive syncs.)

---

## Task 9: Update CLAUDE.md — document the pivot-write convention

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1:** Find the existing "Mythic+ per-spec is character-identity-filtered" bullet. Append a sibling bullet directly after it:

```markdown
- **Mythic+ team pivot writes bypass `BelongsToMany`.** `dungeon_run_members` is keyed by the unique tuple `(dungeon_run_id, character_name, character_realm, character_region)`, **not** by `character_id` — `character_id` is a nullable secondary FK that resolves to a row in `characters` only when the teammate is one we already track. `SyncCharacterData::syncMythicPlus()` writes this pivot via `DB::table('dungeon_run_members')->updateOrInsert([...unique key cols...], [...])` rather than `BelongsToMany::syncWithoutDetaching`, because Eloquent's pivot upsert is character_id-keyed and (a) silently overwrites multiple unknown teammates onto a single row when given a fallback id, and (b) hits SQLSTATE[23505] when two synced characters share a run with an unknown member. The unknown teammate's `character_id` stays NULL — never falls back to the syncing character's id. Delete-missing semantics within the run match the rest of the slice convention.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(mythic-plus): document dungeon_run_members pivot-write convention"
```

---

## Task 10: Open PR (or hold for review)

- [ ] **Step 1: Push**

```bash
git push -u origin fix/mythic-plus-team-pivot
```

- [ ] **Step 2: Open PR** titled `Fix Mythic+ team pivot — within-sync data loss + cross-character collision`. Body should reference the bug analysis (link this plan), and call out:
  - The schema is unchanged — purely behavioral.
  - The repair command must run once per env after merge (call this out in the PR's deploy notes).
  - Horizon must be restarted in each env after merge.

---

## Verification Summary (operator quick-reference)

After merging and deploying:

1. `php artisan migrate` — no migrations to run; this fix doesn't touch schema. (Sanity-check: should be a no-op.)
2. `docker compose restart horizon` — required so Horizon picks up the rewritten job.
3. `php artisan blizzard:repair-dungeon-run-member-character-ids --dry-run` — review the count, then run without `--dry-run`.
4. Trigger a sync on any previously-affected character (e.g., melaniya). Confirm the job completes green and `mythics_synced_at` updates.
5. Spot-check a known multi-character-shared run in the DB; confirm 5 distinct pivot rows per run with correct `character_id` for tracked members and NULL for unknowns.
6. Tail logs for a few minutes; no `uq_dungeon_run_member` violations should appear.

---

## Open Questions

1. **Should we drop the `BelongsToMany members()` relation on `DungeonRun`?** The relation still works for the read-side API (FE iterates `members[]` via the relation), but it implies an Eloquent-pivot model that's no longer how we *write*. Leaving it in place is fine. If a future refactor makes the read side use `memberEntries()` (the `HasMany DungeonRunMember`), the `BelongsToMany` could be deprecated. Out of scope here; flag in PR comment.

2. **Should the unknown-member resolver also try fuzzy matching (case-insensitive realm)?** Today: name is lowercased, realm matches verbatim. Blizzard sometimes returns realm slugs with variant casing. If the test character "Melodud" appears later as a real character with `realm = 'twisting-nether'`, the resolver should pick it up — verify `Character::realm` is also lowercased on insert (per `BlizzardIdentity::realm`). If not, add `strtolower($realm)` to the resolver. Investigate during implementation; if needed, add a fifth test asserting the realm-case-insensitive resolution.

3. **Should `created_at` on `dungeon_run_members` be preserved across re-syncs?** The simpler `updateOrInsert` path resets `created_at` on every sync. The Plan-2/Plan-4 slices using `updateOrCreate` similarly do not preserve `created_at` precisely. Acceptable. Flag if the FE ever surfaces this column.

4. **Is the unique constraint truly correct?** The user's brief says yes. Reasoning to confirm during implementation: a single Blizzard run can be played by ≤5 unique `(name, realm, region)` triples; the constraint forbids duplicates of the same triple in the same run, which is the right invariant. If a name-realm-region tuple legitimately recurred (impossible in WoW), the constraint would be wrong — but the data model rules that out. **Do not change the schema.**

---

### Critical Files for Implementation

- /home/dakiman/projects/guild-service-v2/backend/app/Blizzard/Jobs/SyncCharacterData.php
- /home/dakiman/projects/guild-service-v2/backend/database/migrations/0001_01_01_000007_create_dungeon_run_members_table.php
- /home/dakiman/projects/guild-service-v2/backend/app/Models/DungeonRun.php
- /home/dakiman/projects/guild-service-v2/backend/app/Models/DungeonRunMember.php
- /home/dakiman/projects/guild-service-v2/backend/app/Console/Commands/BackfillSlices.php
