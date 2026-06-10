# Plan 4 / Slice 5 — Character Achievements

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md` (slice 5, §3 row 5; §4; §5)

**Goal:** Persist and expose every character's completed achievements (top-level only — no criteria progress) so `CharacterAchievementsTab.vue` can render the full list with Wowhead tooltips. Ships LAST in Plan 4 because it is the highest row-volume slice (30k+ rows per max-level character) and deserves an isolated slow-ramp window.

**Architecture:** New `character_achievements` table holding one row per completed achievement with `(character_id, achievement_id)` unique. New DTO/mapper/resource pair, a feature-flag-gated slice in `SyncCharacterData::handle()` that writes via **DELETE-then-chunked-INSERT inside a single transaction** (the existing iterate-then-`updateOrCreate` pattern from `syncRaidEncounters` does not scale to 30k rows). FE adds a `@tanstack/vue-virtual` (already installed) virtualized list — naive Vue rendering of 30k DOM nodes is unworkable. Achievement category grouping and Feats-of-Strength styling are deferred (BE returns raw IDs only — see §4 of spec).

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL (chunked bulk insert via `DB::table()->insert()`), Laravel Horizon, Redis. FE: Vue 3 `<script setup>`, `@tanstack/vue-virtual` 3.x (verify in Task 17), Tailwind/DaisyUI, Wowhead tooltip via existing `WowheadLink.vue`.

**Out of this plan:**
- Achievement criteria progress (`criteria.amount`, `criteria.is_completed` per child criterion) — store top-level completion only (spec §3 row 5).
- Achievement **category** rendering / Feats-of-Strength / Legacy classification — BE returns raw IDs; mapping to category names requires the `/data/wow/achievement-category/index` game-data endpoint, which is its own follow-up slice (spec §4).
- BE pagination of `/api/v1/characters/{id}/achievements` — ship the full list inside `CharacterResource.achievements` for MVP and revisit if payload size or query latency hurts in production. Estimated worst case: 30k rows × ~30 bytes JSON each ≈ 900 KB pre-gzip / ~150 KB after gzip; acceptable.
- Achievement points scalar — already lives in `characters.achievement_points` (Plan 1) and stays untouched.

**Deploy-ready at the end of:** this plan, **with `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=false` in production**. The migration is purely additive. The slow-ramp procedure (Task 21) flips the flag to true after dev/staging soak.

---

## Task 1: Create the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify working tree is clean, branch off `feature/character-collections-and-stats`**

Run:
```bash
git status --short
git checkout feature/character-collections-and-stats
git pull --ff-only
git checkout -b feature/plan-4-slice-5-character-achievements
```

Expected: a new branch is created off the Plan 4 integration branch. If `feature/character-collections-and-stats` does not exist yet, create it first off `master` per spec §2.4 — this slice is the LAST to ship in Plan 4 so the integration branch should already exist with prior slices merged.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: the most recent commit is the prior Plan 4 slice (slice 4 — collections) or, if this is being landed independently, the spec commit `2026-04-28-character-collections-and-stats-design`.

---

## Task 2: Migration — create `character_achievements` table + add `achievements_synced_at` column

**Files:**
- Create: `database/migrations/2026_04_28_100005_create_character_achievements_table.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_04_28_100005_create_character_achievements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('achievement_id');
            $table->bigInteger('completed_timestamp')->nullable();
            $table->timestamps();

            // Lookup pattern: WHERE character_id = ? ORDER BY completed_timestamp DESC.
            // Compound index serves both the upsert path and the FE list query.
            $table->unique(['character_id', 'achievement_id'], 'character_achievements_unique');
            $table->index(['character_id', 'completed_timestamp'], 'character_achievements_recency_idx');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('achievements_synced_at')->nullable()->after('raids_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('achievements_synced_at');
        });

        Schema::dropIfExists('character_achievements');
    }
};
```

- [ ] **Step 2: Run the migration and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_achievements')); var_dump(Schema::hasColumn('characters', 'achievements_synced_at'));"
```

Expected: both `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100005_create_character_achievements_table.php
git commit -m "Add character_achievements table and achievements_synced_at column"
```

---

## Task 3: Add the `CharacterAchievement` Eloquent model

**Files:**
- Create: `app/Models/CharacterAchievement.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterAchievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'achievement_id',
        'completed_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'achievement_id' => 'integer',
            'completed_timestamp' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
```

- [ ] **Step 2: Verify the model loads**

Run:
```bash
php artisan tinker --execute "echo App\Models\CharacterAchievement::class;"
```

Expected: `App\Models\CharacterAchievement`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/CharacterAchievement.php
git commit -m "Add CharacterAchievement model"
```

---

## Task 4: Add the `CharacterAchievement` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterAchievement.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterAchievement
{
    public function __construct(
        public int $achievementId,
        public ?int $completedTimestamp,
    ) {}
}
```

- [ ] **Step 2: Verify the class loads**

Run:
```bash
php -r "require 'vendor/autoload.php'; \$d = new App\Blizzard\DTO\CharacterAchievement(achievementId: 1, completedTimestamp: 1700000000000); echo \$d->achievementId === 1 ? 'ok' : 'bad';"
```

Expected: `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterAchievement.php
git commit -m "Add CharacterAchievement DTO"
```

---

## Task 5: Add the `CharacterAchievementMapper`

**Files:**
- Create: `app/Blizzard/Mappers/CharacterAchievementMapper.php`

The Blizzard `/character/{realm}/{name}/achievements` endpoint returns:

```json
{
  "total_quantity": 1234,
  "total_points": 24500,
  "achievements": [
    {
      "id": 123,
      "achievement": { "id": 123, "name": "..." },
      "criteria": { ... },
      "completed_timestamp": 1700000000000
    }
  ],
  "category_progress": [...],
  "recent_events": [...]
}
```

We map only the `achievements[]` array, taking `id` + `completed_timestamp`. In-progress achievements with `criteria.is_completed=false` are NOT in this list (Blizzard's `achievements[]` is "completed only"); a separate "in progress" key exists but is out of scope per spec §3 row 5.

- [ ] **Step 1: Write the mapper**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterAchievement;

class CharacterAchievementMapper
{
    /**
     * @return CharacterAchievement[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($data['achievements'] ?? [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id === 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $out[] = new CharacterAchievement(
                achievementId: $id,
                completedTimestamp: isset($row['completed_timestamp'])
                    ? (int) $row['completed_timestamp']
                    : null,
            );
        }

        return $out;
    }
}
```

- [ ] **Step 2: Write the unit test**

Create `tests/Unit/Blizzard/Mappers/CharacterAchievementMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterAchievementMapper;
use Tests\TestCase;

class CharacterAchievementMapperTest extends TestCase
{
    public function test_returns_empty_for_null(): void
    {
        $this->assertSame([], (new CharacterAchievementMapper)->map(null));
    }

    public function test_returns_empty_for_missing_achievements_key(): void
    {
        $this->assertSame([], (new CharacterAchievementMapper)->map(['total_quantity' => 0]));
    }

    public function test_maps_top_level_achievements(): void
    {
        $payload = [
            'achievements' => [
                ['id' => 100, 'completed_timestamp' => 1700000000000],
                ['id' => 200, 'completed_timestamp' => 1700000001000],
                ['id' => 300], // no completed_timestamp — in-progress is skipped, but defensive null is OK
            ],
        ];

        $out = (new CharacterAchievementMapper)->map($payload);

        $this->assertCount(3, $out);
        $this->assertSame(100, $out[0]->achievementId);
        $this->assertSame(1700000000000, $out[0]->completedTimestamp);
        $this->assertSame(300, $out[2]->achievementId);
        $this->assertNull($out[2]->completedTimestamp);
    }

    public function test_dedupes_repeated_ids(): void
    {
        $payload = [
            'achievements' => [
                ['id' => 100, 'completed_timestamp' => 1],
                ['id' => 100, 'completed_timestamp' => 2],
            ],
        ];

        $this->assertCount(1, (new CharacterAchievementMapper)->map($payload));
    }

    public function test_skips_zero_id(): void
    {
        $this->assertSame([], (new CharacterAchievementMapper)->map([
            'achievements' => [['id' => 0, 'completed_timestamp' => 1]],
        ]));
    }
}
```

- [ ] **Step 3: Run the test to verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterAchievementMapperTest.php
```

Expected: 5 tests, 5 assertions, OK.

- [ ] **Step 4: Commit**

```bash
git add app/Blizzard/Mappers/CharacterAchievementMapper.php tests/Unit/Blizzard/Mappers/CharacterAchievementMapperTest.php
git commit -m "Add CharacterAchievementMapper with deduplication"
```

---

## Task 6: Add the `CharacterAchievementResource`

**Files:**
- Create: `app/Http/Resources/CharacterAchievementResource.php`

- [ ] **Step 1: Write the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterAchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'achievement_id' => (int) $this->achievement_id,
            'completed_timestamp' => $this->completed_timestamp !== null
                ? (int) $this->completed_timestamp
                : null,
        ];
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/CharacterAchievementResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/CharacterAchievementResource.php
git commit -m "Add CharacterAchievementResource"
```

---

## Task 7: Extend `config/blizzard.php` with feature flag + staleness key

**Files:**
- Modify: `config/blizzard.php`

- [ ] **Step 1: Add `staleness.character.achievements`**

Edit `config/blizzard.php`. In the `staleness.character` block (around line 33-40), add the `achievements` key so the block reads:

```php
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
            'achievements' => (int) env('BLIZZARD_STALE_CHARACTER_ACHIEVEMENTS', 86400),
        ],
```

Default: 24h. Achievements change slowly compared to gear/raids; daily refresh is plenty.

- [ ] **Step 2: Add `sync.achievements_enabled` flag**

In the `sync` block (around line 68-73), append the new flag so the block reads:

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'achievements_enabled' => (bool) env('BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED', false),
    ],
```

**Default `false`** — slice ramps explicitly per Task 21.

- [ ] **Step 3: Verify config loads**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "echo config('blizzard.staleness.character.achievements'); echo PHP_EOL; var_dump(config('blizzard.sync.achievements_enabled'));"
```

Expected: `86400` then `bool(false)`.

- [ ] **Step 4: Commit**

```bash
git add config/blizzard.php
git commit -m "Add achievements staleness threshold and sync feature flag"
```

---

## Task 8: Update `Character` model (`$fillable`, casts, `isAchievementsStale`, relation)

**Files:**
- Modify: `app/Models/Character.php`

- [ ] **Step 1: Add `achievements_synced_at` to `$fillable`**

In `app/Models/Character.php`, find the `$fillable` array and append `'achievements_synced_at',` after `'raids_synced_at',`:

```php
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
        'achievements_synced_at',
    ];
```

- [ ] **Step 2: Add `achievements_synced_at` cast**

In the same file, find the `casts()` method and add `'achievements_synced_at' => 'datetime',` next to the other `*_synced_at` casts:

```php
            'mythics_synced_at' => 'datetime',
            'pvp_synced_at' => 'datetime',
            'professions_synced_at' => 'datetime',
            'raids_synced_at' => 'datetime',
            'achievements_synced_at' => 'datetime',
```

- [ ] **Step 3: Add the `isAchievementsStale()` helper**

At the bottom of the class, after `isRaidsStale()`, add:

```php
    public function isAchievementsStale(): bool
    {
        return ! $this->achievements_synced_at
            || $this->achievements_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.achievements');
    }
```

- [ ] **Step 4: Add the `achievements()` relationship**

In the same file, find the existing relationship methods (e.g. `pvpBrackets()`, `professions()`, `raidEncounterKills()`) and add an `achievements()` method that returns `$this->hasMany(CharacterAchievement::class)`:

```php
    public function achievements(): HasMany
    {
        return $this->hasMany(CharacterAchievement::class);
    }
```

If `HasMany` is not already imported at the top of the file, add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the existing imports.

- [ ] **Step 5: Verify the model loads cleanly**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "\$c = App\Models\Character::factory()->make(); echo \$c->isAchievementsStale() ? 'stale' : 'fresh';"
```

Expected: `stale` (no `achievements_synced_at` on a freshly-made factory instance → null timestamp → returns true).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Character.php
git commit -m "Add achievements_synced_at, isAchievementsStale, and achievements relation to Character"
```

---

## Task 9: Update `CharacterService` staleness OR-chain

**Files:**
- Modify: `app/Services/CharacterService.php`

- [ ] **Step 1: Include `isAchievementsStale()` in the slice-stale check**

In `app/Services/CharacterService.php`, replace this block:

```php
        $anySliceStale = $character->isMythicsStale()
            || $character->isPvpStale()
            || $character->isProfessionsStale()
            || $character->isRaidsStale();
```

with:

```php
        $anySliceStale = $character->isMythicsStale()
            || $character->isPvpStale()
            || $character->isProfessionsStale()
            || $character->isRaidsStale()
            || $character->isAchievementsStale();
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Services/CharacterService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/CharacterService.php
git commit -m "Include achievements staleness in CharacterService slice OR-chain"
```

---

## Task 10: Add `BlizzardProfileClient::getCharacterAchievements()`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardProfileClient.php`

- [ ] **Step 1: Append the new method**

In `app/Blizzard/Client/BlizzardProfileClient.php`, mirror the shape of the existing `getCharacterRaidEncounters()` method and add this method at the end of the class (just before the closing `}`):

```php
    public function getCharacterAchievements(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $token = $this->tokenManager->getToken($this->region);
        $namespace = $this->namespace();
        $baseUrl = $this->baseUrl();
        $timeout = (int) config('blizzard.timeouts.character_pool', 20);

        try {
            $response = Http::withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("/profile/wow/character/{$realm}/{$name}/achievements");

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();

            return $response->json();
        } catch (RequestException $e) {
            // 5xx and timeouts bubble; the BlizzardClient retry middleware handles them upstream.
            throw $e;
        }
    }
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Client/BlizzardProfileClient.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/Client/BlizzardProfileClient.php
git commit -m "Add BlizzardProfileClient::getCharacterAchievements"
```

---

## Task 11: Add `SyncCharacterData::syncAchievements()` slice with chunked bulk insert

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

This is the **performance-critical task**. We do NOT use the `iterate-each-DTO + updateOrCreate + collect-keep-list + reject-and-delete-each` pattern from `syncRaidEncounters` — that pattern issues O(N) UPDATE/INSERT statements plus an O(M) DELETE-per-row, totaling tens of thousands of round-trips for 30k achievements. Instead:

1. Fetch the latest list from Blizzard.
2. Inside one `DB::transaction`:
   - `DELETE FROM character_achievements WHERE character_id = ?` — O(1) statement, ~10ms with the index.
   - Bulk-`INSERT` the new rows in chunks of 1000 (PostgreSQL parameter limit is 65535; at 4 columns/row, 1000 rows = 4000 placeholders, well under the limit).
3. Update `achievements_synced_at`.

This sacrifices the per-row "diff and patch" semantics that makes `syncRaidEncounters` clever, but for an append-only entity (a completed achievement does not "uncomplete") the difference is moot.

- [ ] **Step 1: Add the mapper to the `handle()` signature and the slice call**

In `app/Blizzard/Jobs/SyncCharacterData.php`, find the imports block at the top and add:

```php
use App\Blizzard\Mappers\CharacterAchievementMapper;
use App\Models\CharacterAchievement;
```

Find the `handle()` method signature (around line 73) and add `CharacterAchievementMapper $achievementMapper,` to the parameter list, after `RaidEncounterKillMapper $raidMapper,`:

```php
    public function handle(
        TokenManagerInterface $tokenManager,
        CharacterProfileMapper $profileMapper,
        CharacterMediaMapper $mediaMapper,
        CharacterEquipmentMapper $equipmentMapper,
        CharacterSpecializationMapper $specMapper,
        MythicPlusMapper $mythicPlusMapper,
        MythicPlusRatingMapper $ratingMapper,
        PvpBracketStatsMapper $pvpMapper,
        CharacterProfessionMapper $professionMapper,
        RaidEncounterKillMapper $raidMapper,
        CharacterAchievementMapper $achievementMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

Then in the `Full`-depth block (around line 206-211), append the new slice call after `syncRaidEncounters`:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncAchievements($client, $achievementMapper, $character);
        }
```

- [ ] **Step 2: Add the `syncAchievements()` private method**

At the end of the class, after `syncRaidEncounters()` and before `failed()`, add:

```php
    private function syncAchievements(
        BlizzardProfileClient $client,
        CharacterAchievementMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.achievements_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterAchievements($this->realm, $this->name);
            $dtos = $mapper->map($data);

            DB::transaction(function () use ($character, $dtos) {
                // DELETE-then-bulk-INSERT: cheaper than O(N) updateOrCreate + per-row delete
                // for 30k+ row payloads. Atomic inside the transaction.
                CharacterAchievement::where('character_id', $character->id)->delete();

                if ($dtos !== []) {
                    $now = now();
                    $rows = array_map(fn ($dto) => [
                        'character_id' => $character->id,
                        'achievement_id' => $dto->achievementId,
                        'completed_timestamp' => $dto->completedTimestamp,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $dtos);

                    // PostgreSQL parameter ceiling is 65535; at 5 cols/row, 1000 rows = 5000
                    // placeholders, well under the limit. Chunk to avoid both the parameter
                    // ceiling and excessive memory in pathological cases.
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        CharacterAchievement::insert($chunk);
                    }
                }

                $character->update(['achievements_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Failed to sync achievements for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 3: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "Add achievements slice to SyncCharacterData with chunked bulk insert"
```

---

## Task 12: Stress-test the bulk-insert path (30k row benchmark)

**Files:**
- Create: `tests/Feature/Blizzard/Jobs/SyncCharacterAchievementsStressTest.php`

This test gives the slow-ramp procedure (Task 21) a baseline measurement. It runs against the SQLite in-memory test DB (per `phpunit.xml`), so the absolute timings are NOT directly comparable to Postgres production — but a regression in the chunking logic that goes O(N²) will still blow up here, and the test documents the expected ceiling.

- [ ] **Step 1: Write the stress test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Models\Character;
use App\Models\CharacterAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCharacterAchievementsStressTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_insert_handles_thirty_thousand_rows(): void
    {
        $character = Character::factory()->create();

        $rows = [];
        $now = now();
        for ($i = 1; $i <= 30000; $i++) {
            $rows[] = [
                'character_id' => $character->id,
                'achievement_id' => $i,
                'completed_timestamp' => $i % 7 === 0 ? null : 1700000000000 + $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $start = microtime(true);
        foreach (array_chunk($rows, 1000) as $chunk) {
            CharacterAchievement::insert($chunk);
        }
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertSame(30000, CharacterAchievement::where('character_id', $character->id)->count());

        // Sanity ceiling. Local SQLite typically runs in ~1-3s; bumping past 10s
        // strongly suggests an O(N^2) regression in the chunking code path.
        $this->assertLessThan(
            10000,
            $elapsedMs,
            "30k-row bulk insert took {$elapsedMs} ms — investigate before shipping."
        );

        $this->addToAssertionCount(1);
        fwrite(STDOUT, sprintf("[stress] 30k inserts in %.0f ms\n", $elapsedMs));
    }

    public function test_delete_then_bulk_insert_replaces_existing_rows(): void
    {
        $character = Character::factory()->create();

        CharacterAchievement::insert([
            ['character_id' => $character->id, 'achievement_id' => 1, 'completed_timestamp' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['character_id' => $character->id, 'achievement_id' => 2, 'completed_timestamp' => 200, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Simulate the slice's DELETE-then-INSERT path.
        CharacterAchievement::where('character_id', $character->id)->delete();
        CharacterAchievement::insert([
            ['character_id' => $character->id, 'achievement_id' => 2, 'completed_timestamp' => 250, 'created_at' => now(), 'updated_at' => now()],
            ['character_id' => $character->id, 'achievement_id' => 3, 'completed_timestamp' => 300, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = CharacterAchievement::where('character_id', $character->id)
            ->orderBy('achievement_id')
            ->get(['achievement_id', 'completed_timestamp']);

        $this->assertCount(2, $rows);
        $this->assertSame([2, 3], $rows->pluck('achievement_id')->all());
        $this->assertSame(250, $rows[0]->completed_timestamp);
    }
}
```

- [ ] **Step 2: Run the stress test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncCharacterAchievementsStressTest.php
```

Expected: 2 tests, OK. The first prints `[stress] 30k inserts in NNNN ms` to STDOUT — record this number in the slow-ramp checklist (Task 21) as the SQLite baseline.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Blizzard/Jobs/SyncCharacterAchievementsStressTest.php
git commit -m "Add 30k-row stress test for character achievements bulk insert"
```

---

## Task 13: Update `CharacterResource` and `CharacterController` to expose achievements

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php`
- Modify: `app/Http/Controllers/CharacterController.php`

- [ ] **Step 1: Emit achievements from `CharacterResource::toArray()`**

In `app/Http/Resources/CharacterResource.php`, find the `toArray()` method's return array and add the `achievements` key right after `raid_progress`:

```php
            'pvp_brackets' => PvpBracketResource::collection($this->whenLoaded('pvpBrackets')),
            'professions' => ProfessionResource::collection($this->whenLoaded('professions')),
            'raid_progress' => RaidEncounterResource::collection($this->whenLoaded('raidEncounterKills')),
            'achievements' => CharacterAchievementResource::collection($this->whenLoaded('achievements')),
```

If `CharacterAchievementResource` is not already imported at the top of the file, add `use App\Http\Resources\CharacterAchievementResource;` to the imports (the resource lives in the same namespace, so an import is not strictly required, but matches the explicit-import style in the rest of the file — verify the file's existing convention before adding).

- [ ] **Step 2: Add `achievements` to `meta.freshness`**

In the same file, find the `with()` method and add `'achievements' => $this->freshnessFor('achievements_synced_at', 'achievements'),` to the `freshness` map:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                    'achievements' => $this->freshnessFor('achievements_synced_at', 'achievements'),
                ],
```

- [ ] **Step 3: Eager-load `achievements` in the controller**

In `app/Http/Controllers/CharacterController.php`, find the `show()` method's `$result->load([...])` call and append `'achievements'` to the array:

```php
        $result->load([
            'guild',
            'dungeonRuns.members',
            'pvpBrackets',
            'professions',
            'raidEncounterKills',
            'achievements',
        ]);
```

(The exact existing call may be on a single line — keep it consistent with the file's style. The point is `'achievements'` joins the eager-load list.)

- [ ] **Step 4: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/CharacterResource.php
php -l app/Http/Controllers/CharacterController.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/CharacterResource.php app/Http/Controllers/CharacterController.php
git commit -m "Expose achievements collection and freshness state in CharacterResource"
```

---

## Task 14: Extend `BackfillSlices` to include the new staleness column

**Files:**
- Modify: `app/Console/Commands/BackfillSlices.php`

- [ ] **Step 1: Add the new column to the OR-WHERE-NULL clause**

In `app/Console/Commands/BackfillSlices.php`, find the `where(function ($q) { ... })` block and add `->orWhereNull('achievements_synced_at')`:

```php
            ->where(function ($q) {
                $q->whereNull('mythics_synced_at')
                    ->orWhereNull('pvp_synced_at')
                    ->orWhereNull('professions_synced_at')
                    ->orWhereNull('raids_synced_at')
                    ->orWhereNull('achievements_synced_at');
            })
```

- [ ] **Step 2: Verify no syntax errors and the command still registers**

Run:
```bash
php -l app/Console/Commands/BackfillSlices.php
php artisan list blizzard | grep backfill-slices
```

Expected: no syntax errors; the `blizzard:backfill-slices` line is present.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/BackfillSlices.php
git commit -m "Include achievements_synced_at in BackfillSlices null check"
```

---

## Task 15: Extend the integration test (`RetailCharacterEndpointTest`)

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Add `achievements` to the response-shape assertion**

In `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`, find the `assertJsonStructure` call and add `'achievements'` to the `data` array and `'achievements'` to `meta.freshness`:

```php
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                // ... existing keys ...
                'pvp_brackets',
                'professions',
                'raid_progress',
                'achievements',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => [
                    'profile',
                    'mythic_plus',
                    'pvp',
                    'professions',
                    'raids',
                    'achievements',
                ],
            ],
        ]);
```

- [ ] **Step 2: Append a populated-achievements assertion (only when feature flag is on)**

Below the equipment-shape assertion block, add:

```php
        // Achievements are gated behind BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED. When off,
        // the field is an empty array (whenLoaded with no rows) and we skip the deeper check.
        $achievements = $response->json('data.achievements');
        $this->assertIsArray($achievements);

        if (config('blizzard.sync.achievements_enabled')) {
            $this->assertNotEmpty(
                $achievements,
                'achievements array should not be empty for a live character when the slice is enabled'
            );

            foreach ($achievements as $i => $row) {
                $this->assertArrayHasKey('achievement_id', $row, "achievements[{$i}] missing achievement_id");
                $this->assertArrayHasKey('completed_timestamp', $row, "achievements[{$i}] missing completed_timestamp");
                $this->assertIsInt($row['achievement_id']);
            }
        }
```

- [ ] **Step 3: Verify default suite stays green**

Run:
```bash
composer test
```

Expected: passes.

- [ ] **Step 4: Run the integration suite if credentials are configured**

Only with `BLIZZARD_CLIENT_ID` / `BLIZZARD_CLIENT_SECRET` and at least one filled fixture, AND with `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=true` in the test environment:

```bash
BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=true composer test:integration
```

Expected: tests for filled fixtures pass; `data.achievements` is non-empty for any geared/end-game fixture.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "Extend RetailCharacterEndpointTest with achievements shape assertions"
```

---

## Task 16: Run pint on the BE changes

**Files:** none specific (formatting only)

- [ ] **Step 1: Run pint and verify only this slice's files changed**

Run:
```bash
./vendor/bin/pint
git status --short
```

Expected: pint formats files that this slice touched.

- [ ] **Step 2: Commit pint changes (if any)**

```bash
git add -A
git diff --cached --stat
git commit -m "Run pint on achievements slice" || echo "nothing to commit"
```

---

## Task 17: FE — verify `@tanstack/vue-virtual` and add the TypeScript type

**Files:**
- Modify: `frontend/src/types/character.ts`

- [ ] **Step 1: Verify `@tanstack/vue-virtual` is installed**

Run:
```bash
cd frontend && node -e "console.log(require('@tanstack/vue-virtual/package.json').version)"
```

Expected: a 3.x version is printed. (If this fails, run `npm install @tanstack/vue-virtual` and commit the lockfile change separately.)

- [ ] **Step 2: Add the `CharacterAchievement` interface and the `achievements` field**

In `frontend/src/types/character.ts`, add the `CharacterAchievement` interface between the existing interfaces (after `RaidEncounterProgress`):

```ts
export interface CharacterAchievement {
  achievement_id: number
  completed_timestamp: number | null
}
```

And in the `CharacterResource` interface, add the `achievements` field after `raid_progress`:

```ts
  raid_progress: RaidEncounterProgress[] | null
  achievements: CharacterAchievement[]
```

Also add `'achievements': FreshnessState` to the `MetaBlock.freshness` block:

```ts
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
    achievements: FreshnessState
  }
```

- [ ] **Step 3: Verify FE typecheck passes**

Run:
```bash
cd frontend && npx vue-tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/types/character.ts
git commit -m "Add CharacterAchievement type and achievements field to CharacterResource"
```

---

## Task 18: FE — build the virtualized `AchievementsList` component

**Files:**
- Create: `frontend/src/components/character/AchievementsList.vue`

The list can have 30k entries; mounting them all at once is unworkable. We use `@tanstack/vue-virtual` to render only the visible window (~10-20 rows) plus a small overscan. Each row is a fixed 56px height — cheap to estimate, no measurement pass needed.

Sort default: `completed_timestamp DESC` (most recent completions first); rows with `completed_timestamp === null` (unusual but possible per `CharacterAchievementMapper`) sort to the end.

- [ ] **Step 1: Write the component**

```vue
<template>
  <div class="flex flex-col gap-4">
    <div v-if="!entries || entries.length === 0" class="text-ma-muted/70 text-sm">
      No achievements recorded.
    </div>

    <template v-else>
      <div class="flex justify-between items-center">
        <p class="text-sm text-ma-muted/70">
          {{ entries.length.toLocaleString() }} achievements completed
        </p>
      </div>

      <div ref="parentRef" class="ma-card overflow-y-auto" style="height: 600px;">
        <div
          :style="{
            height: `${virtualizer.getTotalSize()}px`,
            width: '100%',
            position: 'relative',
          }"
        >
          <div
            v-for="virtualRow in virtualizer.getVirtualItems()"
            :key="virtualRow.key"
            :style="{
              position: 'absolute',
              top: 0,
              left: 0,
              width: '100%',
              height: `${virtualRow.size}px`,
              transform: `translateY(${virtualRow.start}px)`,
            }"
            class="px-4 py-3 border-b border-ma-border/30 flex items-center justify-between"
          >
            <WowheadLink
              :href="`achievement=${sorted[virtualRow.index].achievement_id}`"
              class="text-sm"
            >
              Achievement {{ sorted[virtualRow.index].achievement_id }}
            </WowheadLink>
            <span class="text-xs text-ma-muted/60 tabular-nums">
              {{ formatTimestamp(sorted[virtualRow.index].completed_timestamp) }}
            </span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useVirtualizer } from '@tanstack/vue-virtual'
import type { CharacterAchievement } from '@/types/character'
import WowheadLink from '@/components/wow/WowheadLink.vue'

const props = defineProps<{
  entries: CharacterAchievement[]
}>()

const parentRef = ref<HTMLElement | null>(null)

const sorted = computed<CharacterAchievement[]>(() => {
  return [...props.entries].sort((a, b) => {
    const aTs = a.completed_timestamp ?? -1
    const bTs = b.completed_timestamp ?? -1
    return bTs - aTs
  })
})

const virtualizer = useVirtualizer({
  count: computed(() => sorted.value.length),
  getScrollElement: () => parentRef.value,
  estimateSize: () => 56,
  overscan: 8,
})

function formatTimestamp(ms: number | null): string {
  if (ms === null || ms === 0) return '—'
  return new Date(ms).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
</script>
```

> **Note on Wowhead label.** This MVP renders the row label as `Achievement {id}` because BE-side achievement-name resolution requires the `/data/wow/achievement/{id}` game-data endpoint which is not in scope (spec §4). The Wowhead tooltip script (`power.js`, loaded in `index.html`) will rewrite the link's hover content client-side, showing the full name and icon — so the visual experience is fine; only the static text fallback is bare. A follow-up slice that joins BE achievement-name lookups can replace the static label with `{{ achievement_name }}`.

- [ ] **Step 2: Verify FE typecheck passes**

Run:
```bash
cd frontend && npx vue-tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/character/AchievementsList.vue
git commit -m "Add virtualized AchievementsList component with Wowhead tooltips"
```

---

## Task 19: FE — wire `CharacterAchievementsTab.vue` to render the list

**Files:**
- Modify: `frontend/src/pages/character/CharacterAchievementsTab.vue`

- [ ] **Step 1: Replace the EmptyTab stub with the wired component**

Replace the entire contents of `frontend/src/pages/character/CharacterAchievementsTab.vue` with:

```vue
<template>
  <EmptyTab
    v-if="!isReady"
    slice="achievements"
    title="No achievements yet"
    :freshness="freshness"
    :icon="Trophy"
  />
  <AchievementsList v-else :entries="character.achievements" />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Trophy } from 'lucide-vue-next'
import { useCharacterContext } from '@/composables/useCharacterContext'
import EmptyTab from '@/components/character/EmptyTab.vue'
import AchievementsList from '@/components/character/AchievementsList.vue'

const { character, meta } = useCharacterContext()

const freshness = computed(() => meta.value?.freshness?.achievements ?? 'never_synced')
const isReady = computed(
  () => Array.isArray(character.value?.achievements) && character.value.achievements.length > 0,
)
</script>
```

The empty state surfaces while `freshness === 'never_synced'` (slice not yet synced for this character — common until Task 21's ramp completes) or while the array is empty. As soon as rows arrive, the virtualized list takes over.

- [ ] **Step 2: Verify FE typecheck and the tab loads**

Run:
```bash
cd frontend && npx vue-tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/character/CharacterAchievementsTab.vue
git commit -m "Wire CharacterAchievementsTab to virtualized AchievementsList"
```

---

## Task 20: Update `CLAUDE.md` (BE) with the achievements slice notes

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Add a per-slice note in the Blizzard Module section**

Edit `backend/CLAUDE.md`. Under the **Blizzard Module** subsection, find the existing `- **Per-slice Full sync with feature flags.**` bullet and append a new bullet after it:

```
- **Achievements slice uses DELETE-then-bulk-INSERT.** `character_achievements` rows are written via a single `DELETE FROM character_achievements WHERE character_id = ?` followed by chunked `Model::insert($rows)` (1000 rows per chunk) inside one `DB::transaction`. Unlike the other slices' `updateOrCreate` + per-row delete pattern, this avoids O(N) round-trips for the 30k-row payloads max-level characters produce. Achievements are append-only so per-row diff semantics buy nothing. The `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED` flag defaults to `false` in `config/blizzard.php` — flip it on after the Task 21 ramp procedure in the slice 5 plan. Achievement category / Feats-of-Strength / name resolution all require `/data/wow/achievement/{id}` lookups and are out of this slice's scope.
```

- [ ] **Step 2: Update the "Sync Depth" subsection**

Find the `**Full**: standard + ...` line and append `+ achievements` to the list:

```
- **Full**: standard + mythic+ dungeon runs + mythic+ rating + pvp brackets + professions + raid encounter kills + achievements
```

- [ ] **Step 3: Update the per-slice helpers list**

Find the line that reads `Character additionally has per-slice helpers: isMythicsStale(), isPvpStale(), isProfessionsStale(), isRaidsStale().` and append `, isAchievementsStale()`:

```
`Character` additionally has per-slice helpers: `isMythicsStale()`, `isPvpStale()`, `isProfessionsStale()`, `isRaidsStale()`, `isAchievementsStale()`.
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "Document achievements slice patterns in CLAUDE.md"
```

---

## Task 21: Slow-ramp procedure (production rollout checklist)

**Files:** none (operational runbook only — this is the "definition of done" for the production rollout, not a code task)

This task runs AFTER all prior tasks land on `master` with the flag still defaulting to `false`. The prod environment receives the migration and the new code, but `syncAchievements()` returns early on every Full sync until the flag flips.

- [ ] **Step 1: Soak in dev/staging with the flag on**

Set `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=true` in dev and staging `.env` files. Restart the queue/horizon container.

```bash
docker compose exec app php artisan config:clear
docker compose restart horizon
```

Trigger a manual sync against 5 known characters representing different points in the row-volume distribution: a fresh-50, a mid-game alt, two raiding mains, and one veteran with the highest achievement count available.

```bash
docker compose exec app php artisan tinker --execute "App\Blizzard\Jobs\SyncCharacterData::dispatch('eu', 'silvermoon', 'someone', App\Enums\SyncDepth::Full);"
```

Watch:
- `docker compose logs -f horizon` — sync duration per character.
- `psql -c "SELECT character_id, COUNT(*) FROM character_achievements GROUP BY character_id ORDER BY 2 DESC LIMIT 10;"` — row counts.
- `psql -c "EXPLAIN ANALYZE SELECT * FROM character_achievements WHERE character_id = 1 ORDER BY completed_timestamp DESC LIMIT 100;"` — index usage.

Pass criteria: per-character sync completes in under 5 seconds; row counts are within an order of magnitude of expectations (most characters under 5k, mains under 30k); the recency-index appears in the EXPLAIN plan.

- [ ] **Step 2: Production canary**

Once dev/staging is clean, set `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=true` in production but only run `php artisan blizzard:backfill-slices --limit=10` to seed 10 popular characters. **Do not** wait for the natural Full-sync flow (which would fan out to thousands of characters at once via Tier 1 ProactiveSyncCharacters).

Monitor for 30 minutes:
- Horizon job duration percentiles for `blizzard-user-sync` and `blizzard-background` queues.
- DB CPU and write IOPS.
- The `/api/v1/characters/{region}/{realm}/{name}` p95 response time (since the controller eager-loads the new `achievements` relation).

Rollback signal: if any of (sync p95 > 10s, DB CPU > 70% for >5min, API p95 doubles), set the env flag back to `false` and revert `docker compose restart horizon`. The DELETE-then-INSERT path is idempotent so the next sync after re-enable cleans up partial state.

- [ ] **Step 3: Full ramp**

After a clean canary, raise the backfill limit progressively: 100 → 1000 → 10000, with at least 30 minutes between bumps. Once the full backfill is drained, leave the flag on and let `ProactiveSyncCharacters` keep things fresh on the standard cadence.

- [ ] **Step 4: Document the rollout outcome**

Append a one-paragraph note to `docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md` under a new `## 7. Rollout log` section:

```
## 7. Rollout log

- 2026-MM-DD — Slice 5 (achievements) ramped to 100% in production. Peak per-character sync duration NN ms; max row count NN,NNN per character. Endpoint p95 unchanged. No rollback.
```

(Adjust numbers from the canary measurements.)

---

## Task 22: Final verification and PR

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run:
```bash
composer test
```

Expected: passes, including the new mapper unit test and stress test.

- [ ] **Step 2: Run pint --test**

Run:
```bash
./vendor/bin/pint --test
```

Expected: no formatting drift.

- [ ] **Step 3: Run the FE typecheck and build**

Run:
```bash
cd frontend && npm run build
```

Expected: build succeeds with no `vue-tsc` errors.

- [ ] **Step 4: Verify migrations**

Run:
```bash
php artisan migrate:status | tail -5
```

Expected: `2026_04_28_100005_create_character_achievements_table` is listed as `Ran`.

- [ ] **Step 5: Push the branch and open the PR**

```bash
git push -u origin feature/plan-4-slice-5-character-achievements
gh pr create \
  --base feature/character-collections-and-stats \
  --title "Plan 4 / Slice 5: Character achievements" \
  --body "Implements the achievements slice per docs/superpowers/plans/2026-04-28-character-achievements-slice.md. Ships behind BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=false; ramp procedure in Task 21 of the plan."
```

Expected: PR opens against the Plan 4 integration branch.

---

## Done criteria for slice 5

- [ ] `character_achievements` table migrated with `(character_id, achievement_id)` unique and `(character_id, completed_timestamp)` recency index.
- [ ] `characters.achievements_synced_at` column exists.
- [ ] `CharacterAchievement` model, DTO, mapper, and Resource exist.
- [ ] `BlizzardProfileClient::getCharacterAchievements()` fetches `/profile/wow/character/{realm}/{name}/achievements`.
- [ ] `SyncCharacterData::syncAchievements()` writes via DELETE-then-chunked-INSERT (1000 rows per chunk) inside a `DB::transaction`.
- [ ] `Character::isAchievementsStale()` exists and is included in `CharacterService`'s slice OR-chain.
- [ ] `CharacterResource` emits `data.achievements` and `meta.freshness.achievements`; `CharacterController::show()` eager-loads the `achievements` relation.
- [ ] `BackfillSlices` includes `achievements_synced_at` in its null check.
- [ ] `BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED` defaults to `false`; staleness threshold defaults to 24h.
- [ ] Unit test (`CharacterAchievementMapperTest`) and 30k-row stress test pass.
- [ ] `RetailCharacterEndpointTest` asserts the new shape and (when the flag is on) populated rows.
- [ ] FE: `CharacterAchievement` type added, `AchievementsList.vue` renders via `@tanstack/vue-virtual` (already installed), `CharacterAchievementsTab.vue` swaps EmptyTab for the list when rows are present, default sort `completed_timestamp DESC`, Wowhead tooltips wired.
- [ ] BE `CLAUDE.md` documents the slice's bulk-insert pattern, the new sync-depth contents, and the new staleness helper.
- [ ] Slow-ramp procedure (Task 21) is documented; production rollout checklist is ready to execute when the slice merges.
- [ ] Default `composer test` and `npm run build` pass.
- [ ] Branch `feature/plan-4-slice-5-character-achievements` is open as a PR against `feature/character-collections-and-stats`.
