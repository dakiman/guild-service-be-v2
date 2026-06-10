# Plan 5 — Cleanup Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` (sub-slice 5 in §2.7 + decision §2.8 — cleanup).

**Goal:** Remove the five Plan 4 `BLIZZARD_SYNC_*_ENABLED` feature flags (`STATS`, `TITLES`, `REPUTATIONS`, `COLLECTIONS`, `ACHIEVEMENTS`) plus their `if (! config('blizzard.sync.*_enabled')) { return; }` guards in `SyncCharacterData::handle()`. Plan 4 slices become unconditionally always-on once this slice merges. Plan 2 flags (`MYTHIC_PLUS`, `PVP`, `PROFESSIONS`, `RAIDS`) are out of scope — they default to `true` already and remain as kill switches.

**Architecture:** Strictly subtractive. No new code, no new tests. Edits delete: 5 keys from `config/blizzard.php`, 5 env entries from `.env.example`, 5 `if (! config(...))` guard blocks in `SyncCharacterData.php`, 4 `markTestSkipped` flag gates in `RetailCharacterEndpointTest.php`, and 5 "default false / ramp manually" mentions across the per-slice CLAUDE.md bullets. The slice produces no schema changes and no FE work.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL.

**Out of scope:** Plan 2 flags. Stats, titles, reputations, collections, achievements *implementation* (the slice methods themselves stay — only their guards go). Removal of `BLIZZARD_SYNC_GAME_DATA_*` env vars (Plan 5 didn't introduce a feature flag; only `BLIZZARD_GAME_DATA_CACHE_TTL` exists and stays).

**Sequencing:** Last sub-slice on `feature/plan-5-game-data-resolver`. Lands after `plan-5-factions`, `plan-5-titles`, `plan-5-mounts`, `plan-5-achievements` are all merged. Final fast-forward of the umbrella branch into `master` happens after this slice's PR merges.

---

## ⚠️ Operator Gate (read first — pre-merge requirement)

This plan **must not be merged to master** until all five Plan 4 sync slices have been ramped to `true` in production (`BLIZZARD_SYNC_STATS_ENABLED`, `BLIZZARD_SYNC_TITLES_ENABLED`, `BLIZZARD_SYNC_REPUTATIONS_ENABLED`, `BLIZZARD_SYNC_COLLECTIONS_ENABLED`, `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED`) and observed stable for at least one full sync cycle (≥ 24 h for the longest-cadence slice — collections at `BLIZZARD_STALE_CHARACTER_COLLECTIONS=86400`s).

Once the flags are removed, the only way to disable a misbehaving slice is a code revert. That trade-off is the whole point of this slice — Plan 4 is treated as load-bearing once verified — but the operator must accept it explicitly. Document the prod-verification confirmation in the PR description before merging (Task 10 below).

---

## Task 1: Operator pre-flight verification (PR-description gate)

**Files:** none (operator-only)

- [ ] **Step 1: Confirm prod env has all 5 Plan 4 flags set to `true`**

The operator (the human reviewing this PR) inspects production environment configuration via the deploy console / secrets manager (or `kubectl get cm -o yaml` etc., depending on how the env is wired). Verify:

```
BLIZZARD_SYNC_STATS_ENABLED=true
BLIZZARD_SYNC_TITLES_ENABLED=true
BLIZZARD_SYNC_REPUTATIONS_ENABLED=true
BLIZZARD_SYNC_COLLECTIONS_ENABLED=true
BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=true
```

Expected: all five values are exactly `true` (string or boolean per the env loader). Anything else (`false`, missing, `"false"`, `0`) blocks the slice.

- [ ] **Step 2: Confirm at least one Full-sync cycle has elapsed since the flags were flipped**

Operator inspects Horizon dashboard or production logs for evidence that `SyncCharacterData::handle()` has run at `SyncDepth::Full` and successfully updated `stats_synced_at`, `titles_synced_at`, `reputations_synced_at`, `collections_synced_at`, and `achievements_synced_at` on a sample of recently-synced characters within the last 24 h.

Sample query (run in prod read replica):
```sql
SELECT
  COUNT(*) FILTER (WHERE stats_synced_at        > now() - interval '24 hours') AS stats,
  COUNT(*) FILTER (WHERE titles_synced_at       > now() - interval '24 hours') AS titles,
  COUNT(*) FILTER (WHERE reputations_synced_at  > now() - interval '24 hours') AS reputations,
  COUNT(*) FILTER (WHERE collections_synced_at  > now() - interval '24 hours') AS collections,
  COUNT(*) FILTER (WHERE achievements_synced_at > now() - interval '24 hours') AS achievements
FROM characters
WHERE game_version = 'retail';
```

Expected: each count is non-trivial (at least the popular-character set). Zero in any column means that slice never wrote in prod — investigate before proceeding.

- [ ] **Step 3: Record the verification in the PR description**

When opening the PR for this slice (Task 10), the description must include a section like:

```markdown
## Plan 4 prod verification

Verified by <operator-name> on <YYYY-MM-DD>:
- [x] All 5 BLIZZARD_SYNC_*_ENABLED flags set to `true` in prod env
- [x] Full-sync cycle observed in prod within the last 24 h (stats=N, titles=N, reps=N, collections=N, achievements=N)
- [x] No flag-flip rollbacks pending
```

This is the explicit accept-the-trade-off step that gates merge.

- [ ] **Step 4: No commit (operator step only)**

---

## Task 2: Remove the five Plan 4 flag keys from `config/blizzard.php`

**Files:**
- Modify: `config/blizzard.php:73-83`

- [ ] **Step 1: Locate the current `'sync' => [...]` block**

Run:
```bash
grep -n "stats_enabled\|titles_enabled\|reputations_enabled\|collections_enabled\|achievements_enabled" backend/config/blizzard.php
```

Expected output:
```
78:        'stats_enabled' => (bool) env('BLIZZARD_SYNC_STATS_ENABLED', false),
79:        'titles_enabled' => (bool) env('BLIZZARD_SYNC_TITLES_ENABLED', false),
80:        'reputations_enabled' => (bool) env('BLIZZARD_SYNC_REPUTATIONS_ENABLED', false),
81:        'collections_enabled' => (bool) env('BLIZZARD_SYNC_COLLECTIONS_ENABLED', false),
82:        'achievements_enabled' => (bool) env('BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED', false),
```

- [ ] **Step 2: Edit the file**

In `backend/config/blizzard.php`, find this exact block (lines 73-83):

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'stats_enabled' => (bool) env('BLIZZARD_SYNC_STATS_ENABLED', false),
        'titles_enabled' => (bool) env('BLIZZARD_SYNC_TITLES_ENABLED', false),
        'reputations_enabled' => (bool) env('BLIZZARD_SYNC_REPUTATIONS_ENABLED', false),
        'collections_enabled' => (bool) env('BLIZZARD_SYNC_COLLECTIONS_ENABLED', false),
        'achievements_enabled' => (bool) env('BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED', false),
    ],
```

Replace with (the four Plan 2 keys remain; the five Plan 4 keys are deleted):

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
    ],
```

- [ ] **Step 3: Update the comment block above the section**

Find the `Per-Slice Sync Feature Flags` comment block (lines 65-72) which still references "each retail Full-sync slice". Replace this block:

```php
    /*
    |--------------------------------------------------------------------------
    | Per-Slice Sync Feature Flags
    |--------------------------------------------------------------------------
    | Each retail Full-sync slice can be individually disabled via env so a
    | misbehaving slice can be killed without a code revert.
    */
```

with:

```php
    /*
    |--------------------------------------------------------------------------
    | Per-Slice Sync Feature Flags (Plan 2 only)
    |--------------------------------------------------------------------------
    | Plan 2 retail slices (mythic+, pvp, professions, raids) keep individual
    | kill-switch env flags. The Plan 4 slices (stats, titles, reputations,
    | collections, achievements) were removed in Plan 5 — they now run
    | unconditionally. To disable one of those, revert the slice in code.
    */
```

- [ ] **Step 4: Sanity-check the file parses**

Run:
```bash
cd backend
php -l config/blizzard.php
```

Expected: `No syntax errors detected in config/blizzard.php`.

- [ ] **Step 5: Commit**

Run:
```bash
git add config/blizzard.php
git commit -m "chore(plan-5): remove Plan 4 BLIZZARD_SYNC_*_ENABLED config keys"
```

---

## Task 3: Remove the five Plan 4 env vars from `.env.example`

**Files:**
- Modify: `.env.example:73-82`

- [ ] **Step 1: Locate the current env block**

Run:
```bash
grep -n "BLIZZARD_SYNC" backend/.env.example
```

Expected output:
```
73:# Per-slice sync gates. Plan 2 slices default true; Plan 4 slices default false — flip to ramp.
74:BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED=true
75:BLIZZARD_SYNC_PVP_ENABLED=true
76:BLIZZARD_SYNC_PROFESSIONS_ENABLED=true
77:BLIZZARD_SYNC_RAIDS_ENABLED=true
78:BLIZZARD_SYNC_STATS_ENABLED=false
79:BLIZZARD_SYNC_TITLES_ENABLED=false
80:BLIZZARD_SYNC_REPUTATIONS_ENABLED=false
81:BLIZZARD_SYNC_COLLECTIONS_ENABLED=false
82:BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=false
```

- [ ] **Step 2: Edit the file**

In `backend/.env.example`, find this exact block:

```
# Per-slice sync gates. Plan 2 slices default true; Plan 4 slices default false — flip to ramp.
BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED=true
BLIZZARD_SYNC_PVP_ENABLED=true
BLIZZARD_SYNC_PROFESSIONS_ENABLED=true
BLIZZARD_SYNC_RAIDS_ENABLED=true
BLIZZARD_SYNC_STATS_ENABLED=false
BLIZZARD_SYNC_TITLES_ENABLED=false
BLIZZARD_SYNC_REPUTATIONS_ENABLED=false
BLIZZARD_SYNC_COLLECTIONS_ENABLED=false
BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=false
```

Replace with:

```
# Per-slice sync gates (Plan 2 only — Plan 4 slices are unconditional after Plan 5 cleanup).
BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED=true
BLIZZARD_SYNC_PVP_ENABLED=true
BLIZZARD_SYNC_PROFESSIONS_ENABLED=true
BLIZZARD_SYNC_RAIDS_ENABLED=true
```

- [ ] **Step 3: Verify**

Run:
```bash
grep -c "BLIZZARD_SYNC" backend/.env.example
```

Expected: `4` (the four Plan 2 entries remain).

- [ ] **Step 4: Commit**

Run:
```bash
git add .env.example
git commit -m "chore(plan-5): remove Plan 4 BLIZZARD_SYNC_*_ENABLED env vars from .env.example"
```

---

## Task 4: Remove the five `if (! config('blizzard.sync.*_enabled'))` guards in `SyncCharacterData.php`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php` (5 separate edits, one per slice method)

Each guard is the *first three lines* of the slice method (after the `Character $character,) : void {` line). The `try { ... } catch` body and the `*_synced_at` update remain. Do each edit individually so a botched edit on one slice doesn't taint the others.

- [ ] **Step 1: Verify the guards are at the expected line numbers**

Run:
```bash
grep -n "blizzard.sync\." backend/app/Blizzard/Jobs/SyncCharacterData.php
```

Expected output (lines for Plan 2 stay; lines 470, 496, 540, 587, 658 are the Plan 4 guards we delete):
```
246:        if (! config('blizzard.sync.mythic_plus_enabled')) {
312:        if (! config('blizzard.sync.pvp_enabled')) {
374:        if (! config('blizzard.sync.professions_enabled')) {
421:        if (! config('blizzard.sync.raids_enabled')) {
470:        if (! config('blizzard.sync.stats_enabled')) {
496:        if (! config('blizzard.sync.titles_enabled')) {
540:        if (! config('blizzard.sync.reputations_enabled')) {
587:        if (! config('blizzard.sync.collections_enabled')) {
658:        if (! config('blizzard.sync.achievements_enabled')) {
```

(The Plan 2 guards at 246/312/374/421 are out of scope — leave them.)

- [ ] **Step 2: Remove the `stats` guard (around line 470)**

Find this exact block:

```php
        if (! config('blizzard.sync.stats_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterStats($this->realm, $this->name);
```

Replace with (the `try` line is preserved, the four lines above it deleted):

```php
        try {
            $data = $client->getCharacterStats($this->realm, $this->name);
```

- [ ] **Step 3: Remove the `titles` guard (around line 496)**

Find this exact block:

```php
        if (! config('blizzard.sync.titles_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterTitles($this->realm, $this->name);
```

Replace with:

```php
        try {
            $data = $client->getCharacterTitles($this->realm, $this->name);
```

- [ ] **Step 4: Remove the `reputations` guard (around line 540)**

Find this exact block:

```php
        if (! config('blizzard.sync.reputations_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterReputations($this->realm, $this->name);
```

Replace with:

```php
        try {
            $data = $client->getCharacterReputations($this->realm, $this->name);
```

- [ ] **Step 5: Remove the `collections` guard (around line 587)**

Find this exact block:

```php
        if (! config('blizzard.sync.collections_enabled')) {
            return;
        }

        try {
            $bodies = $client->getCharacterCollections($this->realm, $this->name);
```

Replace with:

```php
        try {
            $bodies = $client->getCharacterCollections($this->realm, $this->name);
```

- [ ] **Step 6: Remove the `achievements` guard (around line 658)**

Find this exact block:

```php
        if (! config('blizzard.sync.achievements_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterAchievements($this->realm, $this->name);
```

Replace with:

```php
        try {
            $data = $client->getCharacterAchievements($this->realm, $this->name);
```

- [ ] **Step 7: Re-grep to confirm the five Plan 4 guards are gone, four Plan 2 guards remain**

Run:
```bash
grep -n "blizzard.sync\." backend/app/Blizzard/Jobs/SyncCharacterData.php
```

Expected output:
```
246:        if (! config('blizzard.sync.mythic_plus_enabled')) {
312:        if (! config('blizzard.sync.pvp_enabled')) {
374:        if (! config('blizzard.sync.professions_enabled')) {
421:        if (! config('blizzard.sync.raids_enabled')) {
```

Exactly four lines, all Plan 2. No `stats_enabled`, `titles_enabled`, `reputations_enabled`, `collections_enabled`, or `achievements_enabled`.

- [ ] **Step 8: Sanity-check syntax**

Run:
```bash
cd backend
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: `No syntax errors detected ...`.

- [ ] **Step 9: Commit**

Run:
```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "chore(plan-5): drop Plan 4 if-config guards in SyncCharacterData"
```

---

## Task 5: Remove the four `markTestSkipped` flag gates in `RetailCharacterEndpointTest.php`

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

The four gates skip tests when `config('blizzard.sync.*_enabled')` is false. With the flags gone the gates are dead code — `config(...)` will always return null, the `if (! null)` is `true`, and the test will always skip. Remove the gate and let the test always run; the assertion bodies stay intact.

- [ ] **Step 1: Locate the gates**

Run:
```bash
grep -n "blizzard.sync\." backend/tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected output:
```
161:        if (! config('blizzard.sync.reputations_enabled')) {
206:        if (! config('blizzard.sync.titles_enabled')) {
245:        if (! config('blizzard.sync.collections_enabled')) {
294:        if (! config('blizzard.sync.achievements_enabled')) {
```

- [ ] **Step 2: Remove the reputations gate (around line 161)**

Find this exact block:

```php
        if (! config('blizzard.sync.reputations_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_REPUTATIONS_ENABLED is false; populated-reputations assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

Replace with (remove the four-line gate; the `$url = ...` line stays):

```php
        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

- [ ] **Step 3: Remove the titles gate (around line 206)**

Find this exact block:

```php
        if (! config('blizzard.sync.titles_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_TITLES_ENABLED is false; populated-titles assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

Replace with:

```php
        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

- [ ] **Step 4: Remove the collections gate (around line 245)**

Find this exact block:

```php
        if (! config('blizzard.sync.collections_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_COLLECTIONS_ENABLED is false; populated-collections assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

Replace with:

```php
        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

- [ ] **Step 5: Remove the achievements gate (around line 294)**

Find this exact block:

```php
        if (! config('blizzard.sync.achievements_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED is false; populated-achievements assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

Replace with:

```php
        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
```

- [ ] **Step 6: Update the test method docblocks**

Each of the four test methods has a docblock above it referencing the `BLIZZARD_SYNC_*_ENABLED` flag — these are now stale. Search and update.

For `test_retail_endpoint_includes_reputations_when_flag_enabled`: the docblock isn't shown in the grep output but exists above line 156. The test method *name* (`..._when_flag_enabled`) is also stale. Rename:

Find: `test_retail_endpoint_includes_reputations_when_flag_enabled`
Replace with: `test_retail_endpoint_includes_reputations`

Repeat for the other three:
- `test_retail_endpoint_includes_titles_when_flag_enabled` → `test_retail_endpoint_includes_titles`
- `test_retail_endpoint_includes_collections_when_flag_enabled` → `test_retail_endpoint_includes_collections`
- `test_retail_endpoint_includes_achievements_when_flag_enabled` → `test_retail_endpoint_includes_achievements`

Update the docblocks above each method (the comments saying "only populates when BLIZZARD_SYNC_X_ENABLED=true" / "skip cleanly when the flag is off"). Replace those comments with a single line: `Reputations` / `Titles` / `Collections` / `Achievements` — `assert response shape.`.

For `test_retail_endpoint_includes_reputations` specifically there is also a stale assertion message at line 177 that mentions the flag — find:

```php
                'rep_grinder fixture should expose at least one reputation entry; set BLIZZARD_SYNC_REPUTATIONS_ENABLED=true and re-run if empty.',
```

Replace with:

```php
                'rep_grinder fixture should expose at least one reputation entry.',
```

- [ ] **Step 7: Re-grep to confirm no more Plan 4 flag references in the test file**

Run:
```bash
grep -n "BLIZZARD_SYNC_\|blizzard.sync\." backend/tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected output: no matches. (If any remain, re-read and remove.)

- [ ] **Step 8: Run the affected tests to confirm they still pass (or skip cleanly when fixtures are absent)**

Run:
```bash
cd backend
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected: all tests pass or skip on missing-fixture cleanly. None should error or fail.

- [ ] **Step 9: Commit**

Run:
```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "test(plan-5): drop Plan 4 flag-skip gates from retail endpoint tests"
```

---

## Task 6: Update `backend/CLAUDE.md` to drop the "default false / ramp manually" mentions

**Files:**
- Modify: `backend/CLAUDE.md` (5 bullet edits + 1 architecture-note edit)

CLAUDE.md currently calls out the five Plan 4 flag defaults across multiple bullets. Now that the flags are gone, these are stale or actively misleading.

- [ ] **Step 1: Update the architecture-note bullet (line 68)**

Find this exact line:

```markdown
- **Per-slice Full sync with feature flags.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs nine independent slice writes (mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements) after the Standard-depth writes. Each slice is gated on `config('blizzard.sync.{slice}_enabled')` (backed by `BLIZZARD_SYNC_{SLICE}_ENABLED` env, default true for Plan 2 slices and false for Plan 4 slices), wraps its own try/catch around a single `DB::transaction`, and owns a `*_synced_at` column plus a config staleness threshold. One slice failing never aborts the others; `*_synced_at` updates only on success. Kill a misbehaving slice via env without a revert.
```

Replace with:

```markdown
- **Per-slice Full sync.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs nine independent slice writes (mythic+, pvp, professions, raids, stats, titles, reputations, collections, achievements) after the Standard-depth writes. Each slice wraps its own try/catch around a single `DB::transaction`, and owns a `*_synced_at` column plus a config staleness threshold. One slice failing never aborts the others; `*_synced_at` updates only on success. Plan 2 slices (mythic+, pvp, professions, raids) keep individual `BLIZZARD_SYNC_{SLICE}_ENABLED` kill-switches (default true) — flip to false to disable without a revert. Plan 4 slices (stats, titles, reputations, collections, achievements) **run unconditionally** since Plan 5 verified prod stability and removed their flags; disabling one of those now requires a code revert.
```

- [ ] **Step 2: Update the **Stats slice.** bullet (line 72)**

Find this exact line:

```markdown
- **Stats slice.** `stats` JSONB column on `characters` carries the Blizzard `/character-stats` payload (envelope keys `_links` and `character` stripped). The slice is gated on `BLIZZARD_SYNC_STATS_ENABLED` (default false) and tracks freshness via `stats_synced_at`. A 404 from Blizzard writes `stats = null` and updates `stats_synced_at` (delete-missing semantics).
```

Replace with:

```markdown
- **Stats slice.** `stats` JSONB column on `characters` carries the Blizzard `/character-stats` payload (envelope keys `_links` and `character` stripped). Tracks freshness via `stats_synced_at`. A 404 from Blizzard writes `stats = null` and updates `stats_synced_at` (delete-missing semantics). Always-on after Plan 5 cleanup.
```

- [ ] **Step 3: Update the **Titles slice.** bullet (line 73)**

Find this exact line:

```markdown
- **Titles slice.** `character_titles` rows carry `(title_id, name, display_string, is_selected)` where `is_selected` flags the character's currently equipped title (zero or one row per character). Display string is whatever Blizzard returns on the character `/titles` endpoint — gender-specific variants live on the per-title game-data endpoint and are out of scope for this slice. `BLIZZARD_SYNC_TITLES_ENABLED` defaults to `false` (ramp manually per environment).
```

Replace with:

```markdown
- **Titles slice.** `character_titles` rows carry `(title_id, name, display_string, is_selected)` where `is_selected` flags the character's currently equipped title (zero or one row per character). Display string is whatever Blizzard returns on the character `/titles` endpoint — gender-specific variants come from the game-data resolver (Plan 5 titles slice). Always-on after Plan 5 cleanup.
```

- [ ] **Step 4: Update the **Reputations slice.** bullet (line 74)**

Find this exact line:

```markdown
- **Reputations slice.** `character_reputations` rows carry `(faction_id, faction_name, standing, value, max)` with delete-missing inside `DB::transaction`. `value` is `standing.raw` (lossless cumulative rep — keeps the data round-trippable without needing Blizzard's per-tier crosswalk). `standing` is the lowercased name (`hated`..`exalted`). `BLIZZARD_SYNC_REPUTATIONS_ENABLED` defaults `false` — flip on to enable the slice without a code revert. Paragon counts and renown levels are deferred to a follow-up slice (require additional per-faction endpoint calls).
```

Replace with:

```markdown
- **Reputations slice.** `character_reputations` rows carry `(faction_id, faction_name, standing, value, max)` with delete-missing inside `DB::transaction`. `value` is `standing.raw` (lossless cumulative rep — keeps the data round-trippable without needing Blizzard's per-tier crosswalk). `standing` is the lowercased name (`hated`..`exalted`). Always-on after Plan 5 cleanup. Paragon counts and renown levels were dropped from scope per Plan 5 spec §2.4 (outdated features).
```

- [ ] **Step 5: Update the **Achievements slice (Plan 4).** bullet (line 75)**

Find this exact line:

```markdown
- **Achievements slice (Plan 4).** Uses **DELETE-then-bulk-INSERT** instead of the sibling slices' `updateOrCreate` + per-row delete pattern. `SyncCharacterData::syncAchievements()` fetches `/character/{realm}/{name}/achievements`, then inside one `DB::transaction` issues `DELETE FROM character_achievements WHERE character_id = ?` followed by chunked `Model::insert($rows)` (1000 rows per chunk — well under PostgreSQL's 65535-parameter ceiling). This avoids O(N) round-trips for the 30k-row payloads max-level characters produce; achievements are append-only so per-row diff semantics buy nothing. Schema includes a `(character_id, completed_timestamp)` recency index alongside the `(character_id, achievement_id)` unique so the FE's "most recent first" sort is fast. `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED` defaults to `false` — ramp via the slow-ramp procedure in `docs/superpowers/plans/2026-04-28-character-achievements-slice.md` Task 21 (canary 10 popular characters → monitor sync p95 / DB CPU / API p95 → progressive backfill 100 → 1000 → 10000). Achievement category, Feats-of-Strength rendering, and BE-side name resolution all require `/data/wow/achievement/{id}` lookups and are out of this slice's scope.
```

Replace with:

```markdown
- **Achievements slice (Plan 4).** Uses **DELETE-then-bulk-INSERT** instead of the sibling slices' `updateOrCreate` + per-row delete pattern. `SyncCharacterData::syncAchievements()` fetches `/character/{realm}/{name}/achievements`, then inside one `DB::transaction` issues `DELETE FROM character_achievements WHERE character_id = ?` followed by chunked `Model::insert($rows)` (1000 rows per chunk — well under PostgreSQL's 65535-parameter ceiling). This avoids O(N) round-trips for the 30k-row payloads max-level characters produce; achievements are append-only so per-row diff semantics buy nothing. Schema includes a `(character_id, completed_timestamp)` recency index alongside the `(character_id, achievement_id)` unique so the FE's "most recent first" sort is fast. Always-on after Plan 5 cleanup (was previously slow-ramped via the procedure in `docs/superpowers/plans/2026-04-28-character-achievements-slice.md` Task 21). Achievement category and Feats-of-Strength rendering come from the Plan 5 game-data resolver (`game_data_achievements` + `game_data_achievement_categories`).
```

- [ ] **Step 6: Update the **Collections slice (Plan 4).** bullet (line 76)**

Find this exact line:

```markdown
- **Collections slice (Plan 4).** `SyncCharacterData::syncCollections()` fetches `/collections/{mounts,pets,toys}` in one parallel `Http::pool()` and writes to three sub-tables (`character_mounts`, `character_pets`, `character_toys`) inside one `DB::transaction` with delete-missing semantics. A single `collections_synced_at` column on `characters` tracks freshness; a single `BLIZZARD_SYNC_COLLECTIONS_ENABLED` flag (default `false`) gates the entire slice. Pets persist `creature_display_id` so the FE can link via Wowhead's `npc=` widget; toys persist `toy_id` for `item=` linking; mounts persist only id + name + is_useable (summon-spell enrichment is a follow-up — the journal mount id is neither item nor spell on its own).
```

Replace with:

```markdown
- **Collections slice (Plan 4).** `SyncCharacterData::syncCollections()` fetches `/collections/{mounts,pets,toys}` in one parallel `Http::pool()` and writes to three sub-tables (`character_mounts`, `character_pets`, `character_toys`) inside one `DB::transaction` with delete-missing semantics. A single `collections_synced_at` column on `characters` tracks freshness; always-on after Plan 5 cleanup. Pets persist `creature_display_id` so the FE can link via Wowhead's `npc=` widget; toys persist `toy_id` for `item=` linking; mounts persist id + name + is_useable, with summon-spell enrichment from the Plan 5 game-data resolver (`game_data_mounts.summon_spell_id`).
```

- [ ] **Step 7: Verify no stale flag references remain**

Run:
```bash
grep -n "BLIZZARD_SYNC_STATS\|BLIZZARD_SYNC_TITLES\|BLIZZARD_SYNC_REPUTATIONS\|BLIZZARD_SYNC_COLLECTIONS\|BLIZZARD_SYNC_ACHIEVEMENTS\|default false\|defaults `false`\|defaults to `false`\|ramp manually" backend/CLAUDE.md
```

Expected: no matches. (If any remain, re-read the file and clean them up. The four Plan 2 flag mentions are fine — they should still match `BLIZZARD_SYNC_MYTHIC_PLUS`, `BLIZZARD_SYNC_PVP`, etc., and *not* match the search above.)

- [ ] **Step 8: Commit**

Run:
```bash
git add CLAUDE.md
git commit -m "docs(plan-5): drop Plan 4 flag/ramp callouts from CLAUDE.md"
```

---

## Task 7: Run full BE test suite

**Files:** none (verification only)

- [ ] **Step 1: Run the suite**

Run:
```bash
cd backend
composer test
```

Expected: all tests pass. Notable signals:
- `RetailCharacterEndpointTest` no longer skips the four flag-gated tests; they now run on every test invocation (and may genuinely fail if a fixture is missing — that's a real assertion now, not a soft skip).
- `SyncCharacterData` slice methods always run; existing tests for stats/titles/reputations/collections/achievements should pass without test-side `config(['blizzard.sync.*_enabled' => true])` setup. (If any test was setting the config explicitly, the test still works — `config(...)` returns null on missing keys, which is fine; the slice runs anyway.)
- No new test failures from missing config keys. PHP returns null for missing config and our slice code no longer reads them, so this is safe.

- [ ] **Step 2: If any test fails**

Likely culprit: a test was setting `config(['blizzard.sync.X_enabled' => true])` in arrange and now an assertion of "X did sync" assumes the flag was the gate. Now the slice always syncs — so the test should still pass *unless* it was relying on the flag-off path (asserting "X did NOT sync"). Search for `assertNull.*synced_at` and review:

```bash
grep -rn "assertNull.*synced_at\|assertEmpty.*titles\|assertEmpty.*reputations\|assertEmpty.*collections\|assertEmpty.*achievements" backend/tests/
```

If a test depends on flag-off behavior, delete the test entirely (the behavior no longer exists). Re-run `composer test` after.

- [ ] **Step 3: No commit unless tests were edited in Step 2**

If you removed or modified additional tests in Step 2, commit:
```bash
git add tests/
git commit -m "test(plan-5): remove tests asserting deleted flag-off behavior"
```

---

## Task 8: FE typecheck + build (defensive — no FE changes expected)

**Files:** none (verification only)

- [ ] **Step 1: Run FE typecheck**

Run:
```bash
cd ../frontend
npx vue-tsc -b
```

Expected: green (no FE changes in this slice — this is a defensive check that nothing else regressed).

- [ ] **Step 2: Run FE build**

Run:
```bash
npm run build
```

Expected: green.

- [ ] **Step 3: No commit (verification only)**

---

## Task 9: Final review pass

**Files:** none (review only)

- [ ] **Step 1: Confirm the cleanup commits land on the feature branch**

Run:
```bash
cd ../backend
git log master..HEAD --oneline | grep "plan-5" | head
```

Expected: a string of commits across all five Plan 5 sub-slices ending with the cleanup commits. The cleanup-specific commits are roughly:

```
chore(plan-5): remove Plan 4 BLIZZARD_SYNC_*_ENABLED config keys
chore(plan-5): remove Plan 4 BLIZZARD_SYNC_*_ENABLED env vars from .env.example
chore(plan-5): drop Plan 4 if-config guards in SyncCharacterData
test(plan-5): drop Plan 4 flag-skip gates from retail endpoint tests
docs(plan-5): drop Plan 4 flag/ramp callouts from CLAUDE.md
```

(Plus optional `test(plan-5): remove tests asserting deleted flag-off behavior` if Task 7 Step 3 fired.)

- [ ] **Step 2: Re-run BE + FE suites end-to-end**

Run:
```bash
composer test && (cd ../frontend && npx vue-tsc -b && npm run build)
```

Expected: both green.

- [ ] **Step 3: Re-grep for any leftover Plan 4 flag references project-wide**

Run:
```bash
grep -rn "BLIZZARD_SYNC_STATS\|BLIZZARD_SYNC_TITLES\|BLIZZARD_SYNC_REPUTATIONS\|BLIZZARD_SYNC_COLLECTIONS\|BLIZZARD_SYNC_ACHIEVEMENTS\|sync\.stats_enabled\|sync\.titles_enabled\|sync\.reputations_enabled\|sync\.collections_enabled\|sync\.achievements_enabled" /home/dakiman/projects/guild-service-v2/backend /home/dakiman/projects/guild-service-v2/frontend 2>/dev/null
```

Expected: no matches anywhere. Anything that remains is a leftover and must be cleaned up before merge.

- [ ] **Step 4: Pint formatting check**

Run:
```bash
./vendor/bin/pint --test
```

Expected: clean. If errors, run `./vendor/bin/pint`, re-stage, and commit:

```bash
git add -A
git commit -m "style(plan-5): pint after cleanup edits"
```

---

## Task 10: Push branch + open the final PR (umbrella merge)

**Files:** none (git only)

- [ ] **Step 1: Push the branch**

Run:
```bash
git push origin feature/plan-5-game-data-resolver
```

(Branch already exists upstream from prior sub-slice PRs — this just pushes the cleanup commits.)

- [ ] **Step 2: Open the final PR**

Open a PR titled: `Plan 5 — cleanup slice (drop Plan 4 BLIZZARD_SYNC_*_ENABLED flags)`.

PR description must reference:
- Spec: `backend/docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` (§2.8).
- This plan: `backend/docs/superpowers/plans/2026-04-30-plan-5-cleanup.md`.
- All four prior sub-slice plans, listed:
  - `backend/docs/superpowers/plans/2026-04-30-plan-5-factions-slice.md`
  - `backend/docs/superpowers/plans/2026-04-30-plan-5-titles-slice.md`
  - `backend/docs/superpowers/plans/2026-04-30-plan-5-mounts-slice.md`
  - `backend/docs/superpowers/plans/2026-04-30-plan-5-achievements-slice.md`
- The operator's Plan 4 prod-verification checklist from Task 1, fully checked.

PR body template:

```markdown
## Summary

Final sub-slice of Plan 5 (game-data resolver). Removes the five Plan 4
`BLIZZARD_SYNC_*_ENABLED` feature flags that were defaulting to `false`
during the cautious Plan 4 ramp. Plan 4 slices now run unconditionally —
to disable one, revert in code.

Specifically removed:
- `config('blizzard.sync.{stats,titles,reputations,collections,achievements}_enabled')` keys + env defaults
- `if (! config(...)) { return; }` guards in `SyncCharacterData::handle()`
- `markTestSkipped` flag gates in `RetailCharacterEndpointTest.php`
- "default false / ramp manually" CLAUDE.md callouts

Plan 2 flags (`MYTHIC_PLUS`, `PVP`, `PROFESSIONS`, `RAIDS`) are out of scope
and remain as kill switches.

## Plan 4 prod verification

Verified by <operator-name> on <YYYY-MM-DD>:
- [x] All 5 BLIZZARD_SYNC_*_ENABLED flags set to `true` in prod env
- [x] Full-sync cycle observed in prod within the last 24 h (stats=N, titles=N, reps=N, collections=N, achievements=N)
- [x] No flag-flip rollbacks pending

## Plan 5 sub-slice index

Plan 5 spec: `backend/docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md`

Sub-slice plans (all merged into `feature/plan-5-game-data-resolver` before this PR):
- factions: `backend/docs/superpowers/plans/2026-04-30-plan-5-factions-slice.md`
- titles: `backend/docs/superpowers/plans/2026-04-30-plan-5-titles-slice.md`
- mounts: `backend/docs/superpowers/plans/2026-04-30-plan-5-mounts-slice.md`
- achievements: `backend/docs/superpowers/plans/2026-04-30-plan-5-achievements-slice.md`
- cleanup (this PR): `backend/docs/superpowers/plans/2026-04-30-plan-5-cleanup.md`

## Test plan

- [x] `composer test` green
- [x] `npx vue-tsc -b && npm run build` green (defensive — no FE changes in this slice)
- [x] No `BLIZZARD_SYNC_{STATS,TITLES,REPUTATIONS,COLLECTIONS,ACHIEVEMENTS}` references in grep across BE/FE source trees
- [x] `pint --test` clean
```

- [ ] **Step 3: Merge strategy**

If sub-slice PRs are landing per-slice (recommended for review hygiene), this is a regular squash-or-merge into `master`. If the umbrella branch was held open and merged once at the end, do a fast-forward merge into `master`:

```bash
git checkout master
git pull
git merge --ff-only feature/plan-5-game-data-resolver
git push
```

(Operator's call. The spec §2.7 and the factions plan Task 24 both note "operator's call.")

- [ ] **Step 4: Delete the umbrella branch**

After merge:

```bash
git branch -d feature/plan-5-game-data-resolver
git push origin --delete feature/plan-5-game-data-resolver
```

- [ ] **Step 5: Close the loop**

Update the project memory or issue tracker noting Plan 5 is complete. The CLAUDE.md update (Task 6) already documents the new state — no further docs changes needed.

---

## Notes

- This slice intentionally has **no schema changes**, **no new tests**, and **no FE changes**. The shape of the response and the persistence model are unchanged. The only observable behavior change is "Plan 4 slices always run on Full sync", which was already the case in prod before this slice (since the operator had flipped the flags to `true` per the operator gate).
- If a future maintainer wants to add a "global Plan 4 kill-switch" back (e.g., to handle a Blizzard API outage), the right shape is **not** to revive these per-slice flags — instead, add a single circuit-breaker flag on `SyncCharacterData::handle()` that short-circuits all slice writes when set. The per-slice flags were too granular to be useful in incident response.
- `BackfillSlices` (`app/Console/Commands/BackfillSlices.php`) does not reference these flags directly (it just queries `*_synced_at IS NULL`), so it needs no changes here.
