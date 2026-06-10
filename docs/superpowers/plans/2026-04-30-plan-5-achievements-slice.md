# Plan 5 — Achievements Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` (sub-slice 4 in §2.7 — achievements).

**Goal:** Resolve `Achievement {id}` fallback rendering at `frontend/src/components/character/AchievementsList.vue:42` by hydrating each character_achievement row with its name + category from a globally-cached game-data table. Adds optional category grouping and Feats-of-Strength filtering on the FE. The biggest sub-slice — two new tables (~40k achievement rows + ~few hundred categories), four new client methods, an extended Artisan command, plus a separate FE-facing endpoint and TanStack Query hook (the only Plan 5 sub-slice that does NOT eager-load on `CharacterResource`).

**Architecture:** Two new tables (`game_data_achievement_categories`, `game_data_achievements`), populated by `php artisan blizzard:sync-game-data achievements` extending the command introduced in plan-5-factions. Categories sync first (FK target). Achievements upsert in chunks of 500 inside their own DB transaction (a single transaction over ~40k rows holds locks too long; chunking + a single retry-tolerant transaction per batch is the correct shape). No eager-load on `CharacterResource` — achievement-heavy max-level characters can carry 5k+ rows, and re-shipping category strings on every character payload is wasteful. Instead, a new endpoint `GET /api/v1/game-data/achievements` returns the full joined table once per session with `Cache-Control: public, max-age=86400` + `ETag`. FE gets a `useGameDataAchievements()` TanStack Query hook with `staleTime: 24h`; `AchievementsList.vue` builds an in-memory `Map<id, GameDataAchievement>` and resolves each character_achievement at render time. Fallback to today's `Achievement {id}` rendering when an ID is missing from the map (new patch, command not yet rerun).

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Vue 3 + TS + DaisyUI + TanStack Vue Query + `@tanstack/vue-virtual`.

**Out of scope (deferred or other slices):** Factions (sub-slice 1, already shipped), titles (sub-slice 2), mounts (sub-slice 3), Plan 4 flag removal (sub-slice 5 cleanup). Achievement criteria progress (per-step list) is permanently out of scope per spec §5.

**Sequencing:** Sub-slice 4. The branch `feature/plan-5-game-data-resolver` already exists and already carries plan-5-factions's commits. This sub-slice extends `BlizzardGameDataClient` and `SyncGameData` rather than introducing them. Branch creation is **not** part of this plan.

**Deploy-ready at the end of:** this plan, after running `php artisan migrate && php artisan blizzard:sync-game-data achievements` in each environment. The achievement sync run takes ~7 minutes against Blizzard's 100 req/s rate limit (~40k detail calls + ~few hundred category calls).

---

## Task 1: Confirm prior slice landed and branch is clean

**Files:** none (git only)

- [ ] **Step 1: Verify branch + clean working tree**

Run:
```bash
cd backend
git status --short
git branch --show-current
git log master..HEAD --oneline | head -20
```

Expected: working tree clean, current branch is `feature/plan-5-game-data-resolver`, log shows the plan-5-factions commits and (if shipped) plan-5-titles + plan-5-mounts commits already present. The branch already carries the `BlizzardGameDataClient::getXxxIndex/getXxx` pattern + the `SyncGameData` Artisan command shape that this plan extends.

- [ ] **Step 2: Confirm prior tables exist**

Run:
```bash
php artisan tinker --execute="
dump(Schema::hasTable('game_data_factions'));
dump(Schema::hasTable('game_data_expansions'));
"
```

Expected: both `true`. If `false`, the prior slice has not landed — stop and execute it first.

---

## Task 2: Migration — `game_data_achievement_categories` table

**Files:**
- Create: `database/migrations/2026_04_30_100005_create_game_data_achievement_categories_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_04_30_100005_create_game_data_achievement_categories_table.php`:

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
        Schema::create('game_data_achievement_categories', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 150);
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->foreign('parent_id')
                ->references('id')
                ->on('game_data_achievement_categories')
                ->nullOnDelete();

            $table->index('parent_id');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_achievement_categories');
    }
};
```

- [ ] **Step 2: Run the migration**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_achievement_categories'));"
```

Expected: `["id", "name", "parent_id", "display_order", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_04_30_100005_create_game_data_achievement_categories_table.php
git commit -m "feat(plan-5): add game_data_achievement_categories table"
```

---

## Task 3: Migration — `game_data_achievements` table

**Files:**
- Create: `database/migrations/2026_04_30_100006_create_game_data_achievements_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_04_30_100006_create_game_data_achievements_table.php`:

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
        Schema::create('game_data_achievements', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedSmallInteger('points')->default(0);
            $table->boolean('is_account_wide')->default(false);
            $table->timestamps();

            $table->foreign('category_id')
                ->references('id')
                ->on('game_data_achievement_categories')
                ->nullOnDelete();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_achievements');
    }
};
```

- [ ] **Step 2: Run the migration**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_achievements'));"
```

Expected: `["id", "name", "description", "category_id", "points", "is_account_wide", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_04_30_100006_create_game_data_achievements_table.php
git commit -m "feat(plan-5): add game_data_achievements table"
```

---

## Task 4: Eloquent models — `GameDataAchievementCategory` and `GameDataAchievement`

**Files:**
- Create: `app/Models/GameDataAchievementCategory.php`
- Create: `app/Models/GameDataAchievement.php`

- [ ] **Step 1: Write `GameDataAchievementCategory` model**

Create `backend/app/Models/GameDataAchievementCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDataAchievementCategory extends Model
{
    protected $fillable = ['id', 'name', 'parent_id', 'display_order'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'parent_id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(GameDataAchievement::class, 'category_id');
    }
}
```

- [ ] **Step 2: Write `GameDataAchievement` model**

Create `backend/app/Models/GameDataAchievement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataAchievement extends Model
{
    protected $fillable = [
        'id',
        'name',
        'description',
        'category_id',
        'points',
        'is_account_wide',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'category_id' => 'integer',
            'points' => 'integer',
            'is_account_wide' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GameDataAchievementCategory::class, 'category_id');
    }
}
```

- [ ] **Step 3: Smoke-test the relations in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
\$cat = GameDataAchievementCategory::create(['id' => 99999, 'name' => 'Test Category', 'display_order' => 0]);
\$ach = GameDataAchievement::create(['id' => 99999, 'name' => 'Test Achievement', 'category_id' => 99999, 'points' => 10]);
dump(\$ach->category->name);
\$ach->delete(); \$cat->delete();
"
```

Expected: dump prints `"Test Category"`, both rows clean up.

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Models/GameDataAchievementCategory.php app/Models/GameDataAchievement.php
git commit -m "feat(plan-5): add GameDataAchievementCategory and GameDataAchievement models"
```

---

## Task 5: DTOs — `GameDataAchievementCategory` and `GameDataAchievement`

**Files:**
- Create: `app/Blizzard/DTO/GameDataAchievementCategory.php`
- Create: `app/Blizzard/DTO/GameDataAchievement.php`

- [ ] **Step 1: Write the category DTO**

Create `backend/app/Blizzard/DTO/GameDataAchievementCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataAchievementCategory
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $parentId,
        public int $displayOrder,
    ) {}
}
```

- [ ] **Step 2: Write the achievement DTO**

Create `backend/app/Blizzard/DTO/GameDataAchievement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataAchievement
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public ?int $categoryId,
        public int $points,
        public bool $isAccountWide,
    ) {}
}
```

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Blizzard/DTO/GameDataAchievementCategory.php app/Blizzard/DTO/GameDataAchievement.php
git commit -m "feat(plan-5): add GameDataAchievementCategory and GameDataAchievement DTOs"
```

---

## Task 6: Mapper — `GameDataAchievementCategoryMapper`

**Files:**
- Create: `app/Blizzard/Mappers/GameDataAchievementCategoryMapper.php`

- [ ] **Step 1: Write the mapper**

Create `backend/app/Blizzard/Mappers/GameDataAchievementCategoryMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataAchievementCategory;

class GameDataAchievementCategoryMapper
{
    /**
     * Map a single Blizzard /data/wow/achievement-category/{id} response
     * to a GameDataAchievementCategory DTO.
     *
     * Response shape (relevant fields):
     *   { id, name, parent_category: { id, name }?, display_order }
     */
    public function mapDetail(?array $data): ?GameDataAchievementCategory
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return new GameDataAchievementCategory(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Unknown'),
            parentId: isset($data['parent_category']['id'])
                ? (int) $data['parent_category']['id']
                : null,
            displayOrder: isset($data['display_order'])
                ? (int) $data['display_order']
                : 0,
        );
    }

    /**
     * Extract category IDs from a /data/wow/achievement-category/index response.
     *
     * Response shape: { categories: [{ id, name, key: { href } }, ...], root_categories: [...], guild_categories: [...] }
     * We pull from `categories` (root + leaf categories live there).
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['categories'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/Mappers/GameDataAchievementCategoryMapper.php
git commit -m "feat(plan-5): add GameDataAchievementCategoryMapper"
```

---

## Task 7: Mapper — `GameDataAchievementMapper`

**Files:**
- Create: `app/Blizzard/Mappers/GameDataAchievementMapper.php`

- [ ] **Step 1: Write the mapper**

Create `backend/app/Blizzard/Mappers/GameDataAchievementMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataAchievement;

class GameDataAchievementMapper
{
    /**
     * Map a single Blizzard /data/wow/achievement/{id} response to a
     * GameDataAchievement DTO.
     *
     * Response shape (relevant fields):
     *   { id, name, description?, category: { id, name }?, points, is_account_wide?: bool }
     *
     * `is_account_wide` is omitted from older achievements; default to false.
     */
    public function mapDetail(?array $data): ?GameDataAchievement
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return new GameDataAchievement(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Unknown'),
            description: isset($data['description'])
                ? (string) $data['description']
                : null,
            categoryId: isset($data['category']['id'])
                ? (int) $data['category']['id']
                : null,
            points: isset($data['points'])
                ? (int) $data['points']
                : 0,
            isAccountWide: (bool) ($data['is_account_wide'] ?? false),
        );
    }

    /**
     * Extract achievement IDs from a /data/wow/achievement/index response.
     *
     * Response shape: { achievements: [{ id, name, key: { href } }, ...] }
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['achievements'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/Mappers/GameDataAchievementMapper.php
git commit -m "feat(plan-5): add GameDataAchievementMapper"
```

---

## Task 8: Mapper tests — `GameDataAchievementCategoryMapperTest`

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataAchievementCategoryMapperTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataAchievementCategoryMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataAchievementCategoryMapper;
use PHPUnit\Framework\TestCase;

class GameDataAchievementCategoryMapperTest extends TestCase
{
    private GameDataAchievementCategoryMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataAchievementCategoryMapper();
    }

    public function test_maps_category_with_parent(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 81,
            'name' => 'Quests',
            'parent_category' => ['id' => 1, 'name' => 'General'],
            'display_order' => 3,
        ]);

        $this->assertSame(81, $dto->id);
        $this->assertSame('Quests', $dto->name);
        $this->assertSame(1, $dto->parentId);
        $this->assertSame(3, $dto->displayOrder);
    }

    public function test_maps_root_category_with_null_parent(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'General',
        ]);

        $this->assertNull($dto->parentId);
    }

    public function test_missing_display_order_defaults_to_zero(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'General',
        ]);

        $this->assertSame(0, $dto->displayOrder);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID']));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'categories' => [
                ['id' => 1, 'name' => 'General'],
                ['id' => 81, 'name' => 'Quests'],
                ['name' => 'no-id'], // skipped
            ],
        ]);

        $this->assertSame([1, 81], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataAchievementCategoryMapperTest.php
```

Expected: 7 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Mappers/GameDataAchievementCategoryMapperTest.php
git commit -m "test(plan-5): cover GameDataAchievementCategoryMapper"
```

---

## Task 9: Mapper tests — `GameDataAchievementMapperTest`

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataAchievementMapperTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataAchievementMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataAchievementMapper;
use PHPUnit\Framework\TestCase;

class GameDataAchievementMapperTest extends TestCase
{
    private GameDataAchievementMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataAchievementMapper();
    }

    public function test_maps_full_achievement(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 230,
            'name' => 'Hatchling of the Talon',
            'description' => 'Obtain 50 mounts.',
            'category' => ['id' => 15246, 'name' => 'Mounts'],
            'points' => 10,
            'is_account_wide' => true,
        ]);

        $this->assertSame(230, $dto->id);
        $this->assertSame('Hatchling of the Talon', $dto->name);
        $this->assertSame('Obtain 50 mounts.', $dto->description);
        $this->assertSame(15246, $dto->categoryId);
        $this->assertSame(10, $dto->points);
        $this->assertTrue($dto->isAccountWide);
    }

    public function test_missing_is_account_wide_defaults_to_false(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Old Achievement',
            'category' => ['id' => 1],
        ]);

        $this->assertFalse($dto->isAccountWide);
    }

    public function test_missing_optional_fields_yield_defaults(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Bare Achievement',
        ]);

        $this->assertNull($dto->description);
        $this->assertNull($dto->categoryId);
        $this->assertSame(0, $dto->points);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID']));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'achievements' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 230, 'name' => 'B'],
                ['name' => 'no-id'], // skipped
            ],
        ]);

        $this->assertSame([1, 230], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataAchievementMapperTest.php
```

Expected: 7 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Mappers/GameDataAchievementMapperTest.php
git commit -m "test(plan-5): cover GameDataAchievementMapper"
```

---

## Task 10: Client methods — `getAchievementCategoryIndex` and `getAchievementCategory`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Append both methods inside the class**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php`. After the last existing method (most likely `getMount` from plan-5-mounts, or `getFaction` if mounts has not yet shipped — append at the end of the class either way), add:

```php
    /**
     * Fetch the achievement-category index from
     * /data/wow/achievement-category/index. Returns the raw response array;
     * mapper extracts IDs.
     *
     * Lives in the static-{region} namespace (patch-pinned reference data) —
     * bypasses request() like getTalentTree()/getFactionIndex().
     */
    public function getAchievementCategoryIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:achievement-category-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement-category/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single achievement category by ID from
     * /data/wow/achievement-category/{id}.
     */
    public function getAchievementCategory(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:achievement-category:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement-category/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/Client/BlizzardGameDataClient.php
git commit -m "feat(plan-5): add getAchievementCategoryIndex and getAchievementCategory client methods"
```

---

## Task 11: Client methods — `getAchievementIndex` and `getAchievement`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Append both methods inside the class**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php` and append after the achievement-category methods:

```php
    /**
     * Fetch the achievement index from /data/wow/achievement/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * Lives in the static-{region} namespace.
     */
    public function getAchievementIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:achievement-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single achievement by ID from /data/wow/achievement/{id}.
     */
    public function getAchievement(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:achievement:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/achievement/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/Client/BlizzardGameDataClient.php
git commit -m "feat(plan-5): add getAchievementIndex and getAchievement client methods"
```

---

## Task 12: Client method tests — append to `BlizzardGameDataClientTest`

**Files:**
- Modify: `tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php` (created in plan-5-factions)

- [ ] **Step 1: Append the four test methods inside the existing test class**

Open `backend/tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php` and append before the closing `}`:

```php
    public function test_get_achievement_category_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement-category/index?*' => Http::response([
                'categories' => [
                    ['id' => 1, 'name' => 'General'],
                    ['id' => 81, 'name' => 'Quests'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getAchievementCategoryIndex();

        $this->assertSame(1, $result['categories'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'achievement-category/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_achievement_category_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement-category/81?*' => Http::response([
                'id' => 81,
                'name' => 'Quests',
                'parent_category' => ['id' => 1, 'name' => 'General'],
                'display_order' => 3,
            ], 200),
        ]);

        $result = $this->client()->getAchievementCategory(81);

        $this->assertSame(81, $result['id']);
        $this->assertSame('Quests', $result['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'achievement-category/81')
                && str_contains($request->url(), 'namespace=static-us');
        });
    }

    public function test_get_achievement_category_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement-category/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getAchievementCategory(99999));
    }

    public function test_get_achievement_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement/index?*' => Http::response([
                'achievements' => [
                    ['id' => 1, 'name' => 'A'],
                    ['id' => 230, 'name' => 'Hatchling of the Talon'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getAchievementIndex();

        $this->assertSame(1, $result['achievements'][0]['id']);
        $this->assertCount(2, $result['achievements']);
    }

    public function test_get_achievement_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['achievements' => []], 200);
        });

        $client = $this->client();
        $client->getAchievementIndex();
        $client->getAchievementIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_achievement_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement/230?*' => Http::response([
                'id' => 230,
                'name' => 'Hatchling of the Talon',
                'category' => ['id' => 15246],
                'points' => 10,
                'is_account_wide' => true,
            ], 200),
        ]);

        $result = $this->client()->getAchievement(230);

        $this->assertSame(230, $result['id']);
        $this->assertTrue($result['is_account_wide']);
    }

    public function test_get_achievement_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/achievement/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getAchievement(99999));
    }
```

- [ ] **Step 2: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
```

Expected: all tests pass (the 7 new ones plus the prior factions/titles/mounts tests).

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
git commit -m "test(plan-5): cover achievement + achievement-category client methods"
```

---

## Task 13: Extend `SyncGameData` Artisan command — add `achievements` arm

**Files:**
- Modify: `app/Console/Commands/SyncGameData.php`

- [ ] **Step 1: Update the file**

Open `backend/app/Console/Commands/SyncGameData.php`. Replace its full contents with:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Mappers\GameDataAchievementCategoryMapper;
use App\Blizzard\Mappers\GameDataAchievementMapper;
use App\Blizzard\Mappers\GameDataFactionMapper;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use App\Models\GameDataFaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGameData extends Command
{
    protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements; omit for all}';

    protected $description = 'Sync static reference data (factions/titles/mounts/achievements) from Blizzard Game Data API into game_data_* tables';

    private const ACHIEVEMENT_CHUNK_SIZE = 500;

    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
        GameDataAchievementCategoryMapper $achievementCategoryMapper,
        GameDataAchievementMapper $achievementMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'achievements'] // titles, mounts added by their slices
            : [$resource];

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'achievements' => $this->syncAchievements($client, $achievementCategoryMapper, $achievementMapper),
                default => $this->error("Unknown resource: {$r}"),
            };
        }

        return self::SUCCESS;
    }

    private function syncFactions(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $mapper,
    ): void {
        // Body unchanged from plan-5-factions; preserve the existing implementation.
        // (This file replacement preserves it; the diff is purely additive at the
        //  match-arm level.)
        $this->info('Syncing factions...');

        $index = $client->getFactionIndex();
        if ($index === null) {
            $this->warn('Faction index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' faction IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getFaction($id);
                } catch (Throwable $e) {
                    Log::warning("Faction sync skipped id={$id}: ".$e->getMessage());
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $dto = $mapper->mapDetail($detail);
                if ($dto === null) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                GameDataFaction::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'parent_faction_id' => $dto->parentFactionId,
                        'expansion_id' => $dto->expansionId,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Factions synced: {$upserted} upserted, {$skipped} skipped.");
    }

    /**
     * Two-phase sync: categories first (FK target for achievements), then
     * achievements in chunks (~40k rows; one DB::transaction over the whole
     * thing holds locks too long, so we wrap each chunk in its own transaction).
     */
    private function syncAchievements(
        BlizzardGameDataClient $client,
        GameDataAchievementCategoryMapper $categoryMapper,
        GameDataAchievementMapper $achievementMapper,
    ): void {
        $this->info('Syncing achievement categories...');

        $catIndex = $client->getAchievementCategoryIndex();
        if ($catIndex === null) {
            $this->warn('Achievement-category index returned null (404). Skipping.');

            return;
        }
        $catIds = $categoryMapper->extractIndexIds($catIndex);
        $this->info('Index returned '.count($catIds).' category IDs.');

        $bar = $this->output->createProgressBar(count($catIds));
        $bar->start();
        $catUpserted = 0;
        $catSkipped = 0;

        DB::transaction(function () use ($client, $categoryMapper, $catIds, &$catUpserted, &$catSkipped, $bar) {
            foreach ($catIds as $id) {
                try {
                    $detail = $client->getAchievementCategory($id);
                } catch (Throwable $e) {
                    Log::warning("Achievement-category sync skipped id={$id}: ".$e->getMessage());
                    $catSkipped++;
                    $bar->advance();

                    continue;
                }

                $dto = $categoryMapper->mapDetail($detail);
                if ($dto === null) {
                    $catSkipped++;
                    $bar->advance();

                    continue;
                }

                GameDataAchievementCategory::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'parent_id' => $dto->parentId,
                        'display_order' => $dto->displayOrder,
                    ],
                );
                $catUpserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Categories synced: {$catUpserted} upserted, {$catSkipped} skipped.");

        // ---- Phase 2: achievements ----
        $this->info('Syncing achievements...');

        $achIndex = $client->getAchievementIndex();
        if ($achIndex === null) {
            $this->warn('Achievement index returned null (404). Skipping.');

            return;
        }
        $achIds = $achievementMapper->extractIndexIds($achIndex);
        $this->info('Index returned '.count($achIds).' achievement IDs.');

        $bar = $this->output->createProgressBar(count($achIds));
        $bar->start();
        $achUpserted = 0;
        $achSkipped = 0;

        // Process in chunks. Each chunk: fetch all detail rows, then one
        // DB::transaction wrapping the chunk's upserts.
        foreach (array_chunk($achIds, self::ACHIEVEMENT_CHUNK_SIZE) as $chunk) {
            $rows = [];

            foreach ($chunk as $id) {
                try {
                    $detail = $client->getAchievement($id);
                } catch (Throwable $e) {
                    Log::warning("Achievement sync skipped id={$id}: ".$e->getMessage());
                    $achSkipped++;
                    $bar->advance();

                    continue;
                }

                $dto = $achievementMapper->mapDetail($detail);
                if ($dto === null) {
                    $achSkipped++;
                    $bar->advance();

                    continue;
                }

                $rows[] = $dto;
            }

            DB::transaction(function () use ($rows, &$achUpserted, $bar) {
                foreach ($rows as $dto) {
                    GameDataAchievement::updateOrCreate(
                        ['id' => $dto->id],
                        [
                            'name' => $dto->name,
                            'description' => $dto->description,
                            'category_id' => $dto->categoryId,
                            'points' => $dto->points,
                            'is_account_wide' => $dto->isAccountWide,
                        ],
                    );
                    $achUpserted++;
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info("Achievements synced: {$achUpserted} upserted, {$achSkipped} skipped.");
    }
}
```

- [ ] **Step 2: Verify the command still registers**

Run:
```bash
php artisan list | grep blizzard:sync-game-data
```

Expected: command appears.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Console/Commands/SyncGameData.php
git commit -m "feat(plan-5): extend sync-game-data command with achievements + categories"
```

---

## Task 14: Artisan command test — append `achievements` cases to `SyncGameDataTest`

**Files:**
- Modify: `tests/Feature/Console/SyncGameDataTest.php` (created in plan-5-factions)

- [ ] **Step 1: Add imports to the existing test file**

Open `backend/tests/Feature/Console/SyncGameDataTest.php`. Ensure the `use` block contains:

```php
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
```

(If they are already there, skip.)

- [ ] **Step 2: Append the test methods inside the existing test class**

Append before the closing `}`:

```php
    public function test_sync_achievements_upserts_categories_then_achievements_with_correct_fk(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);

        // Categories.
        $mock->method('getAchievementCategoryIndex')->willReturn([
            'categories' => [
                ['id' => 1, 'name' => 'General'],
                ['id' => 81, 'name' => 'Quests'],
            ],
        ]);
        $mock->method('getAchievementCategory')->willReturnCallback(function (int $id): array {
            return match ($id) {
                1 => ['id' => 1, 'name' => 'General', 'display_order' => 0],
                81 => ['id' => 81, 'name' => 'Quests', 'parent_category' => ['id' => 1], 'display_order' => 3],
            };
        });

        // Achievements.
        $mock->method('getAchievementIndex')->willReturn([
            'achievements' => [
                ['id' => 5, 'name' => 'A'],
                ['id' => 6, 'name' => 'B'],
            ],
        ]);
        $mock->method('getAchievement')->willReturnCallback(function (int $id): array {
            return match ($id) {
                5 => ['id' => 5, 'name' => 'First Quest', 'category' => ['id' => 81], 'points' => 10, 'is_account_wide' => false],
                6 => ['id' => 6, 'name' => 'Account Quest', 'category' => ['id' => 81], 'points' => 20, 'is_account_wide' => true],
            };
        });

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataAchievementCategory::count());
        $this->assertSame(2, GameDataAchievement::count());

        $quests = GameDataAchievementCategory::find(81);
        $this->assertSame(1, $quests->parent_id, 'sub-category links to parent');

        $first = GameDataAchievement::find(5);
        $this->assertSame(81, $first->category_id);
        $this->assertSame(10, $first->points);
        $this->assertFalse($first->is_account_wide);

        $second = GameDataAchievement::find(6);
        $this->assertTrue($second->is_account_wide);
    }

    public function test_sync_achievements_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getAchievementCategoryIndex')->willReturn([
            'categories' => [['id' => 1, 'name' => 'General']],
        ]);
        $mock->method('getAchievementCategory')->willReturn([
            'id' => 1, 'name' => 'General',
        ]);
        $mock->method('getAchievementIndex')->willReturn([
            'achievements' => [['id' => 5, 'name' => 'A']],
        ]);
        $mock->method('getAchievement')->willReturn([
            'id' => 5, 'name' => 'First', 'category' => ['id' => 1], 'points' => 5,
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements']);

        $this->assertSame(1, GameDataAchievementCategory::count());
        $this->assertSame(1, GameDataAchievement::count());
    }

    public function test_sync_achievements_continues_on_individual_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getAchievementCategoryIndex')->willReturn([
            'categories' => [['id' => 1, 'name' => 'General']],
        ]);
        $mock->method('getAchievementCategory')->willReturn([
            'id' => 1, 'name' => 'General',
        ]);
        $mock->method('getAchievementIndex')->willReturn([
            'achievements' => [
                ['id' => 5, 'name' => 'A'],
                ['id' => 6, 'name' => 'B'],
            ],
        ]);
        $mock->method('getAchievement')->willReturnCallback(function (int $id): array {
            if ($id === 5) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B', 'category' => ['id' => 1], 'points' => 1];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements'])
            ->assertExitCode(0);

        $this->assertNull(GameDataAchievement::find(5));
        $this->assertNotNull(GameDataAchievement::find(6), 'second achievement still upserted');
    }

    public function test_sync_achievements_chunks_inserts_for_large_payloads(): void
    {
        // Build a stub index of 1200 achievements (> 2 chunks at 500 chunk size).
        $achievementRows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $achievementRows[] = ['id' => $i, 'name' => "Achievement #{$i}"];
        }

        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getAchievementCategoryIndex')->willReturn(['categories' => []]);
        $mock->method('getAchievementIndex')->willReturn(['achievements' => $achievementRows]);
        $mock->method('getAchievement')->willReturnCallback(function (int $id): array {
            return ['id' => $id, 'name' => "Achievement #{$id}", 'points' => 0];
        });

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'achievements'])
            ->assertExitCode(0);

        $this->assertSame(1200, GameDataAchievement::count());
    }
```

- [ ] **Step 3: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Console/SyncGameDataTest.php
```

Expected: all tests pass (4 new + the 3 from plan-5-factions).

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Feature/Console/SyncGameDataTest.php
git commit -m "test(plan-5): cover blizzard:sync-game-data achievements resource"
```

---

## Task 15: Resource — `GameDataAchievementResource`

**Files:**
- Create: `app/Http/Resources/GameDataAchievementResource.php`

- [ ] **Step 1: Write the resource**

Create `backend/app/Http/Resources/GameDataAchievementResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameDataAchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'points' => (int) $this->points,
            'is_account_wide' => (bool) $this->is_account_wide,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
                'display_order' => (int) $this->category->display_order,
            ]),
        ];
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Resources/GameDataAchievementResource.php
git commit -m "feat(plan-5): add GameDataAchievementResource"
```

---

## Task 16: Controller — `GameDataController` for the achievements endpoint

**Files:**
- Create: `app/Http/Controllers/GameDataController.php`

- [ ] **Step 1: Write the controller**

Create `backend/app/Http/Controllers/GameDataController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\GameDataAchievementResource;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameDataController extends Controller
{
    /**
     * GET /api/v1/game-data/achievements
     *
     * Returns the full achievements catalog (~40k rows) joined to categories.
     * Designed to be fetched once per session by the FE; HTTP-cached for 24h
     * + ETag-based 304 revalidation.
     */
    public function achievements(Request $request): JsonResponse
    {
        $etag = $this->etag();

        // 304 short-circuit on conditional GET.
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch === $etag) {
            return response()->json(null, 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=86400');
        }

        $achievements = GameDataAchievement::query()
            ->with('category')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => GameDataAchievementResource::collection($achievements),
        ])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Build a stable ETag from the most recent updated_at across the two tables
     * the achievements endpoint depends on.
     */
    private function etag(): string
    {
        $achMax = GameDataAchievement::max('updated_at');
        $catMax = GameDataAchievementCategory::max('updated_at');
        $token = ($achMax ?? 'none').'|'.($catMax ?? 'none');

        return '"'.sha1($token).'"';
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Controllers/GameDataController.php
git commit -m "feat(plan-5): add GameDataController with achievements endpoint"
```

---

## Task 17: Route — `GET /api/v1/game-data/achievements`

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add the route**

Open `backend/routes/api.php`. Add the new use-import near the top:

```php
use App\Http\Controllers\GameDataController;
```

Then add a new section before the existing Blizzard OAuth route:

```php
/*
|--------------------------------------------------------------------------
| Game Data Routes
|--------------------------------------------------------------------------
*/
Route::get('/game-data/achievements', [GameDataController::class, 'achievements'])
    ->name('game-data.achievements');
```

- [ ] **Step 2: Verify the route registers**

Run:
```bash
php artisan route:list | grep game-data
```

Expected: `GET /api/v1/game-data/achievements` listed with the controller method.

- [ ] **Step 3: Commit**

Run:
```bash
git add routes/api.php
git commit -m "feat(plan-5): add /api/v1/game-data/achievements route"
```

---

## Task 18: Endpoint test — `GameDataAchievementsEndpointTest`

**Files:**
- Create: `tests/Feature/Endpoints/GameDataAchievementsEndpointTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Feature/Endpoints/GameDataAchievementsEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataAchievementsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixtures(): void
    {
        GameDataAchievementCategory::create([
            'id' => 1,
            'name' => 'General',
            'parent_id' => null,
            'display_order' => 0,
        ]);
        GameDataAchievementCategory::create([
            'id' => 81,
            'name' => 'Quests',
            'parent_id' => 1,
            'display_order' => 3,
        ]);
        GameDataAchievement::create([
            'id' => 5,
            'name' => 'First Quest',
            'description' => 'Complete your first quest.',
            'category_id' => 81,
            'points' => 10,
            'is_account_wide' => false,
        ]);
        GameDataAchievement::create([
            'id' => 230,
            'name' => 'Hatchling of the Talon',
            'description' => 'Obtain 50 mounts.',
            'category_id' => null,
            'points' => 10,
            'is_account_wide' => true,
        ]);
    }

    public function test_returns_achievements_with_category_block(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/achievements');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', 5);
        $response->assertJsonPath('data.0.name', 'First Quest');
        $response->assertJsonPath('data.0.points', 10);
        $response->assertJsonPath('data.0.is_account_wide', false);
        $response->assertJsonPath('data.0.category.id', 81);
        $response->assertJsonPath('data.0.category.name', 'Quests');
        $response->assertJsonPath('data.0.category.parent_id', 1);

        $response->assertJsonPath('data.1.id', 230);
        $response->assertJsonPath('data.1.is_account_wide', true);
    }

    public function test_omits_category_block_when_no_category_row(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/achievements');

        $response->assertOk();
        // Achievement 230 has category_id = null → category relation missing.
        $response->assertJsonPath('data.1.id', 230);
        $response->assertJsonMissingPath('data.1.category');
    }

    public function test_response_carries_cache_control_and_etag_headers(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/achievements');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'public, max-age=86400');
        $this->assertNotNull($response->headers->get('ETag'));
    }

    public function test_returns_304_on_matching_if_none_match(): void
    {
        $this->seedFixtures();

        $first = $this->getJson('/api/v1/game-data/achievements');
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->getJson('/api/v1/game-data/achievements', [
            'If-None-Match' => $etag,
        ]);

        $second->assertStatus(304);
        $second->assertHeader('ETag', $etag);
    }

    public function test_etag_changes_when_underlying_data_changes(): void
    {
        $this->seedFixtures();

        $first = $this->getJson('/api/v1/game-data/achievements');
        $firstEtag = $first->headers->get('ETag');

        // Mutate an achievement → updated_at moves → ETag changes.
        sleep(1); // ensure distinct updated_at second
        GameDataAchievement::find(5)->update(['name' => 'First Quest (renamed)']);

        $second = $this->getJson('/api/v1/game-data/achievements');
        $this->assertNotSame($firstEtag, $second->headers->get('ETag'));
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/GameDataAchievementsEndpointTest.php
```

Expected: 5 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Feature/Endpoints/GameDataAchievementsEndpointTest.php
git commit -m "test(plan-5): cover /api/v1/game-data/achievements endpoint"
```

---

## Task 19: Frontend — TS types for game-data achievements

**Files:**
- Modify: `frontend/src/types/character.ts`

- [ ] **Step 1: Add new interfaces**

Open `frontend/src/types/character.ts`. Append the following interfaces (placement: near the existing `CharacterAchievement` interface around line 185):

```typescript
export interface GameDataAchievementCategory {
  id: number
  name: string
  parent_id: number | null
  display_order: number
}

export interface GameDataAchievement {
  id: number
  name: string
  description: string | null
  points: number
  is_account_wide: boolean
  category: GameDataAchievementCategory | null
}
```

- [ ] **Step 2: Type-check**

Run:
```bash
cd frontend
npx vue-tsc -b
```

Expected: no new errors.

- [ ] **Step 3: Commit**

Run:
```bash
git add src/types/character.ts
git commit -m "feat(plan-5): add GameDataAchievement and GameDataAchievementCategory types"
```

---

## Task 20: Frontend — API client `game-data.ts`

**Files:**
- Create: `frontend/src/api/game-data.ts`

- [ ] **Step 1: Write the API client**

Create `frontend/src/api/game-data.ts`:

```typescript
import { api } from './client'
import type { GameDataAchievement } from '@/types/character'

interface GameDataAchievementsResponse {
  data: GameDataAchievement[]
}

/**
 * Fetch the full achievements catalog. Designed to be fetched once per
 * session and cached aggressively (server returns Cache-Control: 24h +
 * ETag for 304 revalidation; TanStack Query layers a 24h staleTime on top).
 */
export async function fetchGameDataAchievements(): Promise<GameDataAchievement[]> {
  const response = await api.get<GameDataAchievementsResponse>('/game-data/achievements')

  return response.data.data
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add src/api/game-data.ts
git commit -m "feat(plan-5): add game-data API client (achievements)"
```

---

## Task 21: Frontend — composable `useGameDataAchievements`

**Files:**
- Create: `frontend/src/composables/useGameDataAchievements.ts`

- [ ] **Step 1: Write the composable**

Create `frontend/src/composables/useGameDataAchievements.ts`:

```typescript
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { fetchGameDataAchievements } from '@/api/game-data'
import type { GameDataAchievement } from '@/types/character'

const ONE_DAY_MS = 24 * 60 * 60 * 1000

/**
 * Fetch the full achievements catalog with aggressive client-side caching.
 *
 * Returns:
 *   - `query` — the TanStack Query result (loading, error, data).
 *   - `byId` — a computed Map<id, GameDataAchievement> for O(1) lookup.
 *
 * The catalog is ~1MB JSON, ~40k entries — fetched once per session.
 */
export function useGameDataAchievements() {
  const query = useQuery<GameDataAchievement[]>({
    queryKey: ['game-data', 'achievements'],
    queryFn: fetchGameDataAchievements,
    staleTime: ONE_DAY_MS,
    gcTime: ONE_DAY_MS,
  })

  const byId = computed<Map<number, GameDataAchievement>>(() => {
    const map = new Map<number, GameDataAchievement>()
    for (const a of query.data.value ?? []) {
      map.set(a.id, a)
    }

    return map
  })

  return { query, byId }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add src/composables/useGameDataAchievements.ts
git commit -m "feat(plan-5): add useGameDataAchievements composable"
```

---

## Task 22: Frontend — update `AchievementsList.vue` to resolve via the composable

**Files:**
- Modify: `frontend/src/components/character/AchievementsList.vue`

- [ ] **Step 1: Replace the file contents**

Replace the contents of `frontend/src/components/character/AchievementsList.vue` with:

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
        <label class="flex items-center gap-2 text-xs text-ma-muted/70">
          <input
            type="checkbox"
            class="checkbox checkbox-xs"
            v-model="includeFeatsOfStrength"
          />
          <span>Include Feats of Strength</span>
        </label>
      </div>

      <div ref="parentRef" class="ma-card overflow-y-auto" style="height: 600px">
        <div
          :style="{
            height: `${virtualizer.getTotalSize()}px`,
            width: '100%',
            position: 'relative',
          }"
        >
          <div
            v-for="virtualRow in virtualizer.getVirtualItems()"
            :key="String(virtualRow.key)"
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
            <div class="flex flex-col gap-0.5 min-w-0">
              <a
                :href="`https://www.wowhead.com/achievement=${visible[virtualRow.index].entry.achievement_id}`"
                :data-wowhead="`achievement=${visible[virtualRow.index].entry.achievement_id}`"
                target="_blank"
                rel="noopener"
                class="text-sm truncate"
              >
                {{ visible[virtualRow.index].label }}
              </a>
              <span
                v-if="visible[virtualRow.index].categoryName"
                class="text-[10px] text-ma-muted/50 truncate"
              >
                {{ visible[virtualRow.index].categoryName }}
              </span>
            </div>
            <span class="text-xs text-ma-muted/60 tabular-nums shrink-0 ml-4">
              {{ formatTimestamp(visible[virtualRow.index].entry.completed_timestamp) }}
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
import { useGameDataAchievements } from '@/composables/useGameDataAchievements'
import type { CharacterAchievement } from '@/types/character'

const FEATS_OF_STRENGTH_CATEGORY_NAME = 'Feats of Strength'

const props = defineProps<{
  entries: CharacterAchievement[]
}>()

const parentRef = ref<HTMLElement | null>(null)
const includeFeatsOfStrength = ref(false)

const { byId } = useGameDataAchievements()

interface VisibleRow {
  entry: CharacterAchievement
  label: string
  categoryName: string | null
  isFeatsOfStrength: boolean
}

/**
 * Resolve each character_achievement to its display label + category.
 * Falls back to the legacy "Achievement {id}" rendering when the ID is
 * absent from the catalog (new patch, sync command not yet rerun).
 */
const resolved = computed<VisibleRow[]>(() => {
  const map = byId.value
  const out: VisibleRow[] = []

  for (const entry of props.entries) {
    const meta = map.get(entry.achievement_id)
    const categoryName = meta?.category?.name ?? null
    out.push({
      entry,
      label: meta ? meta.name : `Achievement ${entry.achievement_id}`,
      categoryName,
      isFeatsOfStrength: categoryName === FEATS_OF_STRENGTH_CATEGORY_NAME,
    })
  }

  return out
})

const visible = computed<VisibleRow[]>(() => {
  const filtered = includeFeatsOfStrength.value
    ? resolved.value
    : resolved.value.filter((r) => !r.isFeatsOfStrength)

  return [...filtered].sort((a, b) => {
    const aTs = a.entry.completed_timestamp ?? -1
    const bTs = b.entry.completed_timestamp ?? -1

    return bTs - aTs
  })
})

const virtualizer = useVirtualizer(
  computed(() => ({
    count: visible.value.length,
    getScrollElement: () => parentRef.value,
    estimateSize: () => 56,
    overscan: 8,
  })),
)

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

Notable changes vs. the prior version:
- Imports `useGameDataAchievements` and uses its `byId` map to resolve names + categories.
- New `resolved` computed builds `{ entry, label, categoryName, isFeatsOfStrength }` per row.
- New `includeFeatsOfStrength` toggle hides FoS achievements from the main list by default. Identified by the literal category name `"Feats of Strength"`.
- Falls back to legacy `Achievement {id}` rendering when an ID is missing from the catalog map (preserves pre-Plan-5 behavior).
- Adds a tiny category subtitle line below each name when category is known.

- [ ] **Step 2: Type-check**

Run:
```bash
npx vue-tsc -b
```

Expected: no errors.

- [ ] **Step 3: Build**

Run:
```bash
npm run build
```

Expected: build green.

- [ ] **Step 4: Commit**

Run:
```bash
git add src/components/character/AchievementsList.vue
git commit -m "feat(plan-5): resolve achievement names via useGameDataAchievements; add FoS filter"
```

---

## Task 23: Run the sync command locally to populate dev data

**Files:** none (operational)

- [ ] **Step 1: Run the sync command for achievements**

Run:
```bash
cd backend
php artisan blizzard:sync-game-data achievements
```

Expected: two progress bars (categories first, then achievements). Categories finish in seconds; achievements take ~7 minutes against Blizzard's 100 req/s rate limit. Output ends with two "synced" messages.

- [ ] **Step 2: Verify rows landed**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
dump('categories: '.GameDataAchievementCategory::count());
dump('achievements: '.GameDataAchievement::count());
dump('feats of strength: '.GameDataAchievement::whereHas('category', fn(\$q) => \$q->where('name', 'Feats of Strength'))->count());
"
```

Expected: thousands of categories, ~30k–40k achievements, hundreds of FoS rows.

- [ ] **Step 3: No commit (DB state, not code)**

---

## Task 24: Manual smoke test in dev

**Files:** none (manual)

- [ ] **Step 1: Start the dev stack**

Two terminals:

```bash
cd backend && composer dev
```

```bash
cd frontend && npm run dev
```

- [ ] **Step 2: Look up a character with achievements populated**

In the browser, navigate to a character that has Plan 4 achievements data. Click the **Achievements** tab.

Expected:
- The list now shows real achievement names (e.g. "Hatchling of the Talon") instead of "Achievement {id}".
- A subtitle below each name shows its category (e.g., "Mounts", "Quests / Eastern Kingdoms").
- An "Include Feats of Strength" checkbox is in the header. By default unchecked → FoS rows are hidden.
- Toggling the checkbox includes FoS rows in the list.
- Network tab shows exactly one `/api/v1/game-data/achievements` request per session (response cached in TanStack Query for 24h).

- [ ] **Step 3: Verify the cache works**

Reload the page. Network tab should show:
- `/api/v1/characters/...` re-fetched (per the existing polling/staleness behavior).
- `/api/v1/game-data/achievements` should NOT be re-fetched within 24h (TanStack Query staleness). If it does, the request should return `304 Not Modified` (BE ETag).

- [ ] **Step 4: Verify the legacy fallback**

Open the FE devtools. In the network tab, intercept `/api/v1/game-data/achievements` and replace the response with `{ "data": [] }`. Reload the character page. The Achievements list should render with the legacy `Achievement {id}` labels for every row, no errors.

- [ ] **Step 5: No commit (manual step only)**

---

## Task 25: Final BE + FE verification

**Files:** none (test runs)

- [ ] **Step 1: Full BE test suite**

Run:
```bash
cd backend
composer test
```

Expected: all tests pass. New tests this slice (~21 new): 7 category-mapper, 7 achievement-mapper, 7 client (4 cat + 3 ach), 4 command, 5 endpoint.

- [ ] **Step 2: Full FE typecheck + build**

Run:
```bash
cd ../frontend
npx vue-tsc -b
npm run build
```

Expected: both green.

- [ ] **Step 3: Pint formatting**

Run:
```bash
cd ../backend
./vendor/bin/pint --test
```

Expected: clean. If errors, run `./vendor/bin/pint` and re-stage.

- [ ] **Step 4: No commit unless `pint` modified files**

---

## Task 26: Update CLAUDE.md (backend) with the achievements slice notes

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Append the slice bullet**

Open `backend/CLAUDE.md`. Find the "## Architecture > ### Blizzard Module" bullet list (it already contains the Plan-5 factions/titles/mounts bullets). Append a new bullet:

```markdown
- **Game-data achievements resolver (Plan 5).** Two new tables: `game_data_achievement_categories` (~few hundred rows; `parent_id` self-FK forms the Blizzard category tree) and `game_data_achievements` (~40k rows; `name`, `description`, `category_id` FK, `points`, `is_account_wide`). Both populated by `php artisan blizzard:sync-game-data achievements` (categories phase first as the FK target; achievements phase upserts in chunks of 500 per `DB::transaction` because a single transaction over ~40k rows holds locks too long). Sync command extends `BlizzardGameDataClient` with four `static-{region}` methods (`getAchievement{Index,}`, `getAchievementCategory{Index,}`). **Unique among Plan-5 slices, this one does NOT eager-load on `CharacterResource`** — instead, a dedicated endpoint `GET /api/v1/game-data/achievements` returns the full joined catalog (~1MB JSON) once per session with `Cache-Control: public, max-age=86400` + `ETag`-based 304 revalidation (`GameDataController::achievements()`). The FE caches via TanStack Query (`useGameDataAchievements`, `staleTime: 24h`) and resolves names + categories client-side in `AchievementsList.vue`. Feats-of-Strength achievements are filtered from the main list by category-name match (`'Feats of Strength'`); a checkbox in the header re-includes them. Missing-row fallback: when an `achievement_id` is absent from the catalog map (new patch, sync command not yet rerun), the FE renders the legacy `Achievement {id}` label. No feature flag.
```

- [ ] **Step 2: Commit**

Run:
```bash
git add CLAUDE.md
git commit -m "docs(plan-5): document achievements slice"
```

---

## Task 27: Final review pass

**Files:** none (review only)

- [ ] **Step 1: Confirm all commits land on the feature branch**

Run:
```bash
git log master..HEAD --oneline | head -40
```

Expected: factions, titles, mounts (if shipped), and now achievements commits all land on `feature/plan-5-game-data-resolver`. ~16 new commits this slice.

- [ ] **Step 2: Re-run the full suite**

Run:
```bash
composer test && (cd ../frontend && npx vue-tsc -b && npm run build)
```

Expected: all green.

- [ ] **Step 3: Push the branch**

Run:
```bash
git push origin feature/plan-5-game-data-resolver
```

- [ ] **Step 4: Update the PR (or open one)**

If the per-slice PR strategy is in use, open a new PR from `feature/plan-5-game-data-resolver` into `master` titled `Plan 5 — achievements slice (game-data resolver)`. Body references the spec at `backend/docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` and this plan. If the umbrella-PR strategy is in use, the existing PR's description gains a section noting the achievements slice landed. Operator's choice.

The next sub-slice is `plan-5-cleanup` — drops the five Plan 4 `BLIZZARD_SYNC_*_ENABLED` flags + their `if(config(...))` guards in `SyncCharacterData::handle()` once Plan 4 ramp is verified in production.

---

## Notes on the achievements-specific shape

This slice diverges from the other Plan 5 sub-slices in three load-bearing ways:

1. **No eager-load on `CharacterResource`.** Achievement-heavy max-level characters can carry 5k+ rows; re-shipping repeated category strings on every character payload is wasteful. Achievements is the only resource that warrants a separate endpoint.
2. **Two tables instead of one.** Categories are themselves Blizzard reference data (`/data/wow/achievement-category/...`), and achievements join via `category_id` FK. The sync command runs them in a strict order (categories first).
3. **Chunked upserts.** Wrapping ~40k `updateOrCreate` calls in a single `DB::transaction` holds row locks for the duration of the run (~7 minutes against Blizzard rate limits). Each chunk gets its own transaction; idempotency is preserved because each row's primary key is the deterministic Blizzard ID.

The cleanup slice (sub-slice 5) is the final Plan 5 piece. After that, the umbrella branch fast-forward-merges to master.
