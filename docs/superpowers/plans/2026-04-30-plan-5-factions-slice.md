# Plan 5 — Factions Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` (sub-slice 1 in §2.7 — factions).

**Goal:** Stand up the game-data resolver foundation by hydrating reputations with full faction + expansion metadata. Drops the hardcoded `EXPANSION_BY_FACTION_ID` map currently inlined in `frontend/src/components/character/ReputationsList.vue:54-68`. Establishes the `BlizzardGameDataClient` static-namespace method pattern and the `blizzard:sync-game-data` Artisan command shape that the four follow-up sub-slices (titles, mounts, achievements, cleanup) reuse.

**Architecture:** Two new tables (`game_data_expansions`, `game_data_factions`) seeded by a new Artisan command that hits `/data/wow/reputation-faction/index` and per-ID detail endpoints in the `static-{region}` namespace. Faction-to-expansion mapping is a static array on the new `GameDataFactionMapper` (Blizzard does not expose expansion on the faction endpoint — the existing 11-entry FE map moves server-side). `CharacterReputation` Eloquent model gains a `faction()` belongsTo relation; `ReputationResource` exposes a `faction` block via `whenLoaded`. FE `Reputation` type gains a nested `faction` object; `ReputationsList.vue` reads `reputation.faction.expansion.{name,display_order}` directly. No feature flag — once the migration runs and the sync command populates the table, eager-loading is unconditional. Missing rows render with the existing fallback (Legacy bucket).

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Vue 3 + TS + DaisyUI.

**Out of scope (deferred to other Plan 5 slices):** Title gender variants, mount source/spell, achievement names/categories, removal of Plan 4 `BLIZZARD_SYNC_*_ENABLED` flags. Paragon counts and renown levels were dropped entirely from Plan 5 per spec §2.4.

**Sequencing:** First sub-slice. Establishes the BlizzardGameDataClient method pattern (`getXxxIndex` + `getXxx`), the Artisan command shape (`blizzard:sync-game-data {resource}`), and the eager-load wiring on `CharacterController` that the next three sub-slices extend.

**Deploy-ready at the end of:** this plan, after running `php artisan migrate && php artisan db:seed --class=GameDataExpansionSeeder && php artisan blizzard:sync-game-data factions` in each environment.

---

## Task 1: Create the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify clean working tree, branch from `master`**

Run:
```bash
cd backend
git status --short
git checkout master
git pull
git checkout -b feature/plan-5-game-data-resolver
```

Expected: clean working tree, branch created off the post-Plan-4 `master`.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: most recent commit is the Plan 4 merge (the 5-slice umbrella merged 2026-04-30). The new spec `docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` should be present.

---

## Task 2: Migration — `game_data_expansions` table

**Files:**
- Create: `database/migrations/2026_04_30_100001_create_game_data_expansions_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_04_30_100001_create_game_data_expansions_table.php`:

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
        Schema::create('game_data_expansions', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('name', 100);
            $table->unsignedSmallInteger('display_order');
            $table->timestamps();

            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_expansions');
    }
};
```

- [ ] **Step 2: Run the migration locally**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors. `game_data_expansions` table exists.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_expansions'));"
```

Expected: `["id", "name", "display_order", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_04_30_100001_create_game_data_expansions_table.php
git commit -m "feat(plan-5): add game_data_expansions table"
```

---

## Task 3: Migration — `game_data_factions` table

**Files:**
- Create: `database/migrations/2026_04_30_100002_create_game_data_factions_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_04_30_100002_create_game_data_factions_table.php`:

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
        Schema::create('game_data_factions', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 150);
            $table->unsignedInteger('parent_faction_id')->nullable();
            $table->unsignedSmallInteger('expansion_id')->nullable();
            $table->timestamps();

            $table->foreign('expansion_id')
                ->references('id')
                ->on('game_data_expansions')
                ->nullOnDelete();

            $table->index('parent_faction_id');
            $table->index('expansion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_factions');
    }
};
```

- [ ] **Step 2: Run the migration locally**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors. `game_data_factions` table exists with FK to `game_data_expansions`.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_factions'));"
```

Expected: `["id", "name", "parent_faction_id", "expansion_id", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_04_30_100002_create_game_data_factions_table.php
git commit -m "feat(plan-5): add game_data_factions table"
```

---

## Task 4: Eloquent models — `GameDataExpansion` and `GameDataFaction`

**Files:**
- Create: `app/Models/GameDataExpansion.php`
- Create: `app/Models/GameDataFaction.php`

- [ ] **Step 1: Write `GameDataExpansion` model**

Create `backend/app/Models/GameDataExpansion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDataExpansion extends Model
{
    protected $fillable = ['id', 'name', 'display_order'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function factions(): HasMany
    {
        return $this->hasMany(GameDataFaction::class, 'expansion_id');
    }
}
```

- [ ] **Step 2: Write `GameDataFaction` model**

Create `backend/app/Models/GameDataFaction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataFaction extends Model
{
    protected $fillable = ['id', 'name', 'parent_faction_id', 'expansion_id'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'parent_faction_id' => 'integer',
            'expansion_id' => 'integer',
        ];
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(GameDataExpansion::class, 'expansion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_faction_id');
    }
}
```

- [ ] **Step 3: Smoke-test the relations in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataExpansion;
use App\Models\GameDataFaction;
\$exp = GameDataExpansion::create(['id' => 999, 'name' => 'Test Exp', 'display_order' => 99]);
\$fac = GameDataFaction::create(['id' => 999999, 'name' => 'Test Faction', 'expansion_id' => 999]);
dump(\$fac->expansion->name);
\$fac->delete(); \$exp->delete();
"
```

Expected: dump prints `"Test Exp"`, both rows clean up.

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Models/GameDataExpansion.php app/Models/GameDataFaction.php
git commit -m "feat(plan-5): add GameDataExpansion and GameDataFaction models"
```

---

## Task 5: Seeder — populate `game_data_expansions` with the 11 known expansions

**Files:**
- Create: `database/seeders/GameDataExpansionSeeder.php`

- [ ] **Step 1: Write the seeder**

Create `backend/database/seeders/GameDataExpansionSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GameDataExpansion;
use Illuminate\Database\Seeder;

class GameDataExpansionSeeder extends Seeder
{
    /**
     * Static expansion list, ordered newest-first.
     *
     * Source of truth: WoW expansion release timeline. Add a row each new
     * expansion and update the ordinal of older expansions if the FE renders
     * by reverse-chronological-order (it does — see ReputationsList.vue).
     */
    private const EXPANSIONS = [
        ['id' => 1, 'name' => 'The War Within', 'display_order' => 1],
        ['id' => 2, 'name' => 'Dragonflight', 'display_order' => 2],
        ['id' => 3, 'name' => 'Shadowlands', 'display_order' => 3],
        ['id' => 4, 'name' => 'Battle for Azeroth', 'display_order' => 4],
        ['id' => 5, 'name' => 'Legion', 'display_order' => 5],
        ['id' => 6, 'name' => 'Warlords of Draenor', 'display_order' => 6],
        ['id' => 7, 'name' => 'Mists of Pandaria', 'display_order' => 7],
        ['id' => 8, 'name' => 'Cataclysm', 'display_order' => 8],
        ['id' => 9, 'name' => 'Wrath of the Lich King', 'display_order' => 9],
        ['id' => 10, 'name' => 'The Burning Crusade', 'display_order' => 10],
        ['id' => 11, 'name' => 'Classic', 'display_order' => 11],
    ];

    public function run(): void
    {
        foreach (self::EXPANSIONS as $row) {
            GameDataExpansion::updateOrCreate(['id' => $row['id']], $row);
        }
    }
}
```

- [ ] **Step 2: Run the seeder**

Run:
```bash
php artisan db:seed --class=GameDataExpansionSeeder
```

Expected: 11 rows inserted/upserted into `game_data_expansions`.

- [ ] **Step 3: Verify**

Run:
```bash
php artisan tinker --execute="dump(App\Models\GameDataExpansion::orderBy('display_order')->pluck('name')->toArray());"
```

Expected: `["The War Within", "Dragonflight", "Shadowlands", ..., "Classic"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/seeders/GameDataExpansionSeeder.php
git commit -m "feat(plan-5): seed game_data_expansions with 11 known WoW expansions"
```

---

## Task 6: DTOs — `GameDataFaction` and `GameDataExpansion`

**Files:**
- Create: `app/Blizzard/DTO/GameDataFaction.php`
- Create: `app/Blizzard/DTO/GameDataExpansion.php`

- [ ] **Step 1: Write `GameDataFaction` DTO**

Create `backend/app/Blizzard/DTO/GameDataFaction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataFaction
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $parentFactionId,
        public ?int $expansionId,
    ) {}
}
```

- [ ] **Step 2: Write `GameDataExpansion` DTO** (will be used by titles/mounts plans for symmetry; included here so the DTO directory is complete after the foundational slice)

Create `backend/app/Blizzard/DTO/GameDataExpansion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataExpansion
{
    public function __construct(
        public int $id,
        public string $name,
        public int $displayOrder,
    ) {}
}
```

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Blizzard/DTO/GameDataFaction.php app/Blizzard/DTO/GameDataExpansion.php
git commit -m "feat(plan-5): add GameDataFaction and GameDataExpansion DTOs"
```

---

## Task 7: Mapper — `GameDataFactionMapper` with the static faction→expansion map

**Files:**
- Create: `app/Blizzard/Mappers/GameDataFactionMapper.php`

- [ ] **Step 1: Write the mapper**

Create `backend/app/Blizzard/Mappers/GameDataFactionMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataFaction;

class GameDataFactionMapper
{
    /**
     * Static faction → expansion mapping. Blizzard's
     * /data/wow/reputation-faction/{id} response does not expose expansion,
     * so this map is maintained in-tree and extended each patch.
     *
     * Migrated from the FE's EXPANSION_BY_FACTION_ID map at
     * frontend/src/components/character/ReputationsList.vue (Plan 4 → Plan 5).
     *
     * @var array<int, int> faction_id => expansion_id (matches GameDataExpansionSeeder ids)
     */
    private const FACTION_TO_EXPANSION = [
        // The War Within (expansion_id 1)
        2570 => 1, // Council of Dornogal
        2574 => 1, // The Assembly of the Deeps
        2590 => 1, // Hallowfall Arathi
        2600 => 1, // The Severed Threads
        // Dragonflight (expansion_id 2)
        2510 => 2, // Valdrakken Accord
        2511 => 2, // Iskaara Tuskarr
        2503 => 2, // Maruuk Centaur
        2507 => 2, // Dragonscale Expedition
        2564 => 2, // Loamm Niffen
        2553 => 2, // Soridormi
        2544 => 2, // Artisan's Consortium
    ];

    /**
     * Map a single Blizzard /data/wow/reputation-faction/{id} response
     * to a GameDataFaction DTO.
     */
    public function mapDetail(?array $data): ?GameDataFaction
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $id = (int) $data['id'];

        return new GameDataFaction(
            id: $id,
            name: (string) ($data['name'] ?? 'Unknown'),
            parentFactionId: isset($data['category']['id']) ? (int) $data['category']['id'] : null,
            expansionId: self::FACTION_TO_EXPANSION[$id] ?? null,
        );
    }

    /**
     * Extract faction IDs from a /data/wow/reputation-faction/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['factions'] ?? [] as $entry) {
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
git add app/Blizzard/Mappers/GameDataFactionMapper.php
git commit -m "feat(plan-5): add GameDataFactionMapper with static faction→expansion table"
```

---

## Task 8: Mapper test — `GameDataFactionMapperTest`

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataFactionMapperTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataFactionMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataFactionMapper;
use PHPUnit\Framework\TestCase;

class GameDataFactionMapperTest extends TestCase
{
    private GameDataFactionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataFactionMapper();
    }

    public function test_maps_a_known_TWW_faction_to_expansion_1(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 2570,
            'name' => 'Council of Dornogal',
            'category' => ['id' => 1245],
        ]);

        $this->assertSame(2570, $dto->id);
        $this->assertSame('Council of Dornogal', $dto->name);
        $this->assertSame(1245, $dto->parentFactionId);
        $this->assertSame(1, $dto->expansionId);
    }

    public function test_maps_a_known_dragonflight_faction_to_expansion_2(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 2510,
            'name' => 'Valdrakken Accord',
        ]);

        $this->assertSame(2, $dto->expansionId);
    }

    public function test_unknown_faction_id_yields_null_expansion_id(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 99999,
            'name' => 'Future Faction',
        ]);

        $this->assertNull($dto->expansionId);
    }

    public function test_missing_category_yields_null_parent(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 2570,
            'name' => 'Council of Dornogal',
        ]);

        $this->assertNull($dto->parentFactionId);
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
            'factions' => [
                ['id' => 100, 'name' => 'A'],
                ['id' => 200, 'name' => 'B'],
                ['name' => 'C-no-id'], // skipped
            ],
        ]);

        $this->assertSame([100, 200], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
```

- [ ] **Step 2: Run the test, confirm it fails (mapper not yet implementing all asserted behavior — but it does, so this should already pass)**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataFactionMapperTest.php
```

Expected: 8 tests pass. (TDD purists: the mapper was written before the test in this slice because the static map content is the source of truth and cannot meaningfully be tested-first; the test acts as a regression guard.)

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Mappers/GameDataFactionMapperTest.php
git commit -m "test(plan-5): cover GameDataFactionMapper with 8 cases"
```

---

## Task 9: Client methods — `BlizzardGameDataClient::getFactionIndex()` and `getFaction()`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Add the two methods**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php` and append the following methods inside the class (after `getTalentTree` ends at line 75):

```php
    /**
     * Fetch the reputation-faction index from /data/wow/reputation-faction/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * Lives in the static-{region} namespace (patch-pinned reference data),
     * not dynamic-, so we bypass request() and call Http directly — same
     * convention as getTalentTree() above.
     *
     * Cached aggressively because the index only changes on patches.
     */
    public function getFactionIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:faction-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/reputation-faction/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single reputation-faction by ID from
     * /data/wow/reputation-faction/{id}. Returns the raw response array.
     *
     * Cached for the same TTL as the index.
     */
    public function getFaction(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:faction:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/reputation-faction/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
```

- [ ] **Step 2: Add the new TTL to `config/blizzard.php`**

Open `backend/config/blizzard.php` and locate the existing `talent_tree_cache_ttl` entry. Add adjacent:

```php
'game_data_cache_ttl' => env('BLIZZARD_GAME_DATA_CACHE_TTL', 86400 * 7),
```

- [ ] **Step 3: Update `.env.example`**

Open `backend/.env.example` and add the line near other `BLIZZARD_*` entries:

```
BLIZZARD_GAME_DATA_CACHE_TTL=604800
```

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Blizzard/Client/BlizzardGameDataClient.php config/blizzard.php .env.example
git commit -m "feat(plan-5): add getFactionIndex and getFaction methods to BlizzardGameDataClient"
```

---

## Task 10: Client method tests — `BlizzardGameDataClientTest`

**Files:**
- Create: `tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php` (file may already exist for `getCurrentMythicPlusSeason` / `getTalentTree`; new tests append. Check first.)

- [ ] **Step 1: Check whether the file exists**

Run:
```bash
ls backend/tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php 2>/dev/null && echo EXISTS || echo NEW
```

If `EXISTS`: skip to Step 3.
If `NEW`: continue with Step 2.

- [ ] **Step 2: Create the test file scaffold (only if NEW)**

Create `backend/tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlizzardGameDataClientTest extends TestCase
{
    private function client(): BlizzardGameDataClient
    {
        $tokenManager = $this->createMock(TokenManagerInterface::class);
        $tokenManager->method('getToken')->willReturn('fake-token');

        // Region is a readonly constructor param on the parent BlizzardClient;
        // there is no setter. See BlizzardClient.php:16.
        return new BlizzardGameDataClient($tokenManager, 'us');
    }
}
```

(If `BlizzardGameDataClient` constructor differs, mirror whatever the existing call site uses — the goal is "build a usable client".)

- [ ] **Step 3: Add `getFactionIndex` test**

Append inside the test class:

```php
    public function test_get_faction_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/index?*' => Http::response([
                'factions' => [
                    ['id' => 2510, 'name' => 'Valdrakken Accord'],
                    ['id' => 2570, 'name' => 'Council of Dornogal'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getFactionIndex();

        $this->assertSame(2510, $result['factions'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'reputation-faction/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_faction_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['factions' => []], 200);
        });

        $client = $this->client();
        $client->getFactionIndex();
        $client->getFactionIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_faction_index_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/index?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getFactionIndex());
    }
```

- [ ] **Step 4: Add `getFaction` test**

Append inside the test class:

```php
    public function test_get_faction_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/2510?*' => Http::response([
                'id' => 2510,
                'name' => 'Valdrakken Accord',
                'category' => ['id' => 1245],
            ], 200),
        ]);

        $result = $this->client()->getFaction(2510);

        $this->assertSame(2510, $result['id']);
        $this->assertSame('Valdrakken Accord', $result['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'reputation-faction/2510')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_faction_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/reputation-faction/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getFaction(99999));
    }
```

- [ ] **Step 5: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
```

Expected: all tests pass (the 5 new ones plus any pre-existing).

- [ ] **Step 6: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
git commit -m "test(plan-5): cover getFactionIndex and getFaction client methods"
```

---

## Task 11: Artisan command — `blizzard:sync-game-data`

**Files:**
- Create: `app/Console/Commands/SyncGameData.php`

- [ ] **Step 1: Write the command**

Create `backend/app/Console/Commands/SyncGameData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Mappers\GameDataFactionMapper;
use App\Models\GameDataFaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGameData extends Command
{
    protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements; omit for all}';

    protected $description = 'Sync static reference data (factions/titles/mounts/achievements) from Blizzard Game Data API into game_data_* tables';

    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions']
            : [$resource];

        // Plan-5-titles, mounts, achievements add their cases below as they ship.

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                default => $this->error("Unknown resource: {$r}") || self::FAILURE,
            };
        }

        return self::SUCCESS;
    }

    private function syncFactions(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $mapper,
    ): void {
        $this->info('Syncing factions...');

        // The container resolves a region-bound instance — see
        // BlizzardServiceProvider::register() — so we don't set it here.
        // For multi-region sync, pass a per-region instance: see
        // SyncCharacterData::handle() (line ~178) for the per-region
        // construction pattern.

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
}
```

- [ ] **Step 2: Verify the command registers**

Run:
```bash
php artisan list | grep blizzard:sync-game-data
```

Expected: command appears in the list.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Console/Commands/SyncGameData.php
git commit -m "feat(plan-5): add blizzard:sync-game-data Artisan command (factions support)"
```

---

## Task 12: Artisan command test — `SyncGameDataTest`

**Files:**
- Create: `tests/Feature/Console/SyncGameDataTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Feature/Console/SyncGameDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\GameDataFaction;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncGameDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    public function test_sync_factions_upserts_known_factions_with_expansion_id(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [
                ['id' => 2570, 'name' => 'Council of Dornogal'],
                ['id' => 2510, 'name' => 'Valdrakken Accord'],
                ['id' => 99999, 'name' => 'Unknown future faction'],
            ],
        ]);
        $mock->method('getFaction')->willReturnCallback(function (int $id): array {
            return match ($id) {
                2570 => ['id' => 2570, 'name' => 'Council of Dornogal', 'category' => ['id' => 1245]],
                2510 => ['id' => 2510, 'name' => 'Valdrakken Accord', 'category' => ['id' => 1234]],
                99999 => ['id' => 99999, 'name' => 'Unknown future faction'],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions'])
            ->assertExitCode(0);

        $this->assertSame(3, GameDataFaction::count());

        $tww = GameDataFaction::find(2570);
        $this->assertNotNull($tww);
        $this->assertSame(1, $tww->expansion_id, 'TWW faction maps to expansion 1');

        $df = GameDataFaction::find(2510);
        $this->assertSame(2, $df->expansion_id, 'Dragonflight faction maps to expansion 2');

        $unknown = GameDataFaction::find(99999);
        $this->assertNull($unknown->expansion_id, 'Unknown faction has null expansion_id');
    }

    public function test_sync_factions_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [['id' => 2570, 'name' => 'Council of Dornogal']],
        ]);
        $mock->method('getFaction')->willReturn([
            'id' => 2570, 'name' => 'Council of Dornogal',
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions']);

        $this->assertSame(1, GameDataFaction::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_factions_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [
                ['id' => 2570, 'name' => 'A'],
                ['id' => 2510, 'name' => 'B'],
            ],
        ]);
        $mock->method('getFaction')->willReturnCallback(function (int $id): ?array {
            if ($id === 2570) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B'];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions'])
            ->assertExitCode(0);

        $this->assertNull(GameDataFaction::find(2570));
        $this->assertNotNull(GameDataFaction::find(2510), 'second faction still upserted');
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Console/SyncGameDataTest.php
```

Expected: 3 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Feature/Console/SyncGameDataTest.php
git commit -m "test(plan-5): cover blizzard:sync-game-data factions resource"
```

---

## Task 13: `CharacterReputation` model — add `faction()` relation

**Files:**
- Modify: `app/Models/CharacterReputation.php`

- [ ] **Step 1: Add the relation**

Open `backend/app/Models/CharacterReputation.php` and append after the `character()` method:

```php
    public function faction(): BelongsTo
    {
        return $this->belongsTo(GameDataFaction::class, 'faction_id');
    }
```

- [ ] **Step 2: Smoke-test the relation in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\CharacterReputation;
use App\Models\GameDataFaction;
GameDataFaction::firstOrCreate(['id' => 2570], ['name' => 'Council of Dornogal', 'expansion_id' => 1]);
\$rep = new CharacterReputation([
  'character_id' => 1,
  'faction_id' => 2570,
  'faction_name' => 'Council of Dornogal',
  'standing' => 'exalted',
  'value' => 10000,
  'max' => 0,
]);
\$rep->setRelation('faction', GameDataFaction::find(2570));
dump(\$rep->faction->name);
"
```

Expected: dump prints `"Council of Dornogal"`.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Models/CharacterReputation.php
git commit -m "feat(plan-5): add CharacterReputation::faction() belongsTo relation"
```

---

## Task 14: `CharacterController` — eager-load `reputations.faction.expansion`

**Files:**
- Modify: `app/Http/Controllers/Api/CharacterController.php` (or wherever the show endpoint lives — verify path with `find app -name "CharacterController.php"`)

- [ ] **Step 1: Find the controller**

Run:
```bash
find backend/app -name "CharacterController.php"
```

Expected: one path printed (likely `backend/app/Http/Controllers/Api/CharacterController.php`).

- [ ] **Step 2: Locate the eager-load call**

Open the file. Find the `show()` method (or whichever method returns `CharacterResource`). Look for an existing `loadMissing(...)` or `with(...)` call on the character. Plan 4 added relations like `dungeonRuns.members`. The call site is the same.

- [ ] **Step 3: Add the new relations to the eager-load list**

Whatever the existing eager-load list looks like, add `'reputations.faction.expansion'` to it. Example (your exact existing call may differ — preserve it and just append):

```php
$character->loadMissing([
    // ...existing relations preserved...
    'reputations.faction.expansion',
]);
```

If the controller calls `with([...])` on a query builder instead, append to that list with the same string.

- [ ] **Step 4: Run the existing endpoint test to confirm nothing breaks**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected: existing tests still pass (the new relation just adds joined data; nothing else changes).

- [ ] **Step 5: Commit**

Run:
```bash
git add app/Http/Controllers/
git commit -m "feat(plan-5): eager-load reputations.faction.expansion on character show"
```

---

## Task 15: `ReputationResource` — expose nested `faction` block

**Files:**
- Modify: `app/Http/Resources/ReputationResource.php`

- [ ] **Step 1: Update the resource**

Replace the contents of `backend/app/Http/Resources/ReputationResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReputationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'faction_id' => $this->faction_id,
            'faction_name' => $this->faction_name,
            'standing' => $this->standing,
            'value' => $this->value,
            'max' => $this->max,
            'faction' => $this->whenLoaded('faction', fn () => [
                'id' => $this->faction->id,
                'name' => $this->faction->name,
                'parent_faction_id' => $this->faction->parent_faction_id,
                'expansion' => $this->faction->relationLoaded('expansion') && $this->faction->expansion
                    ? [
                        'id' => $this->faction->expansion->id,
                        'name' => $this->faction->expansion->name,
                        'display_order' => $this->faction->expansion->display_order,
                    ]
                    : null,
            ]),
        ];
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Resources/ReputationResource.php
git commit -m "feat(plan-5): expose nested faction.expansion in ReputationResource"
```

---

## Task 16: Endpoint test — assert `faction` block is in the response

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Open the test**

Run:
```bash
cat backend/tests/Feature/Endpoints/RetailCharacterEndpointTest.php | head -40
```

(Confirm the file exists and inspect its current `setUp` / fixture-building helpers — Plan 4 added populated-data assertions for each slice. Mirror the pattern.)

- [ ] **Step 2: Add a focused test**

Append a new test method inside the test class. Adapt fixture-creation helpers to your existing pattern:

```php
    public function test_reputation_response_includes_faction_block_with_expansion(): void
    {
        // Arrange: create a character with one reputation row and the matching
        // game-data faction + expansion seeded.
        \App\Models\GameDataExpansion::create([
            'id' => 1,
            'name' => 'The War Within',
            'display_order' => 1,
        ]);
        \App\Models\GameDataFaction::create([
            'id' => 2570,
            'name' => 'Council of Dornogal',
            'expansion_id' => 1,
        ]);

        $character = $this->createTestCharacter(); // adapt to existing helper
        $character->reputations()->create([
            'faction_id' => 2570,
            'faction_name' => 'Council of Dornogal',
            'standing' => 'exalted',
            'value' => 21000,
            'max' => 0,
        ]);

        // Act
        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        // Assert
        $response->assertOk();
        $response->assertJsonPath('data.reputations.0.faction.id', 2570);
        $response->assertJsonPath('data.reputations.0.faction.name', 'Council of Dornogal');
        $response->assertJsonPath('data.reputations.0.faction.expansion.id', 1);
        $response->assertJsonPath('data.reputations.0.faction.expansion.name', 'The War Within');
        $response->assertJsonPath('data.reputations.0.faction.expansion.display_order', 1);
    }

    public function test_reputation_response_includes_null_expansion_when_unmapped(): void
    {
        \App\Models\GameDataFaction::create([
            'id' => 99999,
            'name' => 'Future Faction',
            'expansion_id' => null,
        ]);

        $character = $this->createTestCharacter();
        $character->reputations()->create([
            'faction_id' => 99999,
            'faction_name' => 'Future Faction',
            'standing' => 'neutral',
            'value' => 0,
            'max' => 0,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertJsonPath('data.reputations.0.faction.id', 99999);
        $response->assertJsonPath('data.reputations.0.faction.expansion', null);
    }

    public function test_reputation_response_omits_faction_block_when_no_game_data_row(): void
    {
        $character = $this->createTestCharacter();
        $character->reputations()->create([
            'faction_id' => 88888, // no game_data_factions row
            'faction_name' => 'Orphan',
            'standing' => 'neutral',
            'value' => 0,
            'max' => 0,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertJsonPath('data.reputations.0.faction_id', 88888);
        // belongsTo with no matching row → relation is null → whenLoaded emits no key.
        $response->assertJsonMissingPath('data.reputations.0.faction');
    }
```

If `createTestCharacter` does not match an existing helper, replace with whatever fixture-builder Plan 4's tests use (search for `createTestCharacter` in the file).

- [ ] **Step 3: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php --filter=reputation_response
```

Expected: 3 new tests pass.

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "test(plan-5): assert faction.expansion block on reputation responses"
```

---

## Task 17: Schedule weekly sync via Laravel Scheduler

**Files:**
- Modify: `app/Console/Kernel.php` (Laravel 11+: may be `bootstrap/app.php` or `routes/console.php` — verify)

- [ ] **Step 1: Find where commands are scheduled**

Run:
```bash
grep -rn "schedule->" backend/app/Console/ backend/bootstrap/ backend/routes/ 2>/dev/null
```

Expected: one or two hits showing the existing scheduled-command pattern (likely `RefreshBlizzardToken` or `BackfillSlices`).

- [ ] **Step 2: Add the weekly entry**

In whichever file holds the existing schedule, add:

```php
$schedule->command('blizzard:sync-game-data')
    ->weeklyOn(0, '03:00') // Sunday 03:00
    ->withoutOverlapping()
    ->onOneServer();
```

(Adapt the `weeklyOn(...)` arguments to a window the operator prefers; the spec says "weekly", the exact day/time is operator preference. Use `withoutOverlapping` and `onOneServer` because the command is long-running and Horizon may run on multiple instances.)

- [ ] **Step 3: Verify the entry registers**

Run:
```bash
php artisan schedule:list | grep blizzard:sync-game-data
```

Expected: command listed with its weekly cron expression.

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Console/Kernel.php bootstrap/app.php routes/console.php
git commit -m "feat(plan-5): schedule blizzard:sync-game-data weekly"
```

(Stage only files that actually changed.)

---

## Task 18: Run the sync command locally to populate dev data

**Files:** none (operational only — produces DB rows)

- [ ] **Step 1: Run the seeder**

Run:
```bash
php artisan db:seed --class=GameDataExpansionSeeder
```

Expected: 11 expansion rows.

- [ ] **Step 2: Run the sync command for factions**

Run:
```bash
php artisan blizzard:sync-game-data factions
```

Expected: progress bar runs, "N upserted, M skipped" message at end. May take several minutes — Blizzard rate-limited.

- [ ] **Step 3: Verify rows landed**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataFaction;
dump('total: '.GameDataFaction::count());
dump('TWW (id 1) factions: '.GameDataFaction::where('expansion_id', 1)->count());
dump(GameDataFaction::where('expansion_id', 1)->pluck('name')->toArray());
"
```

Expected: total >0, TWW count is 4 (the four 25xx faction IDs from the seeder map), names match the FE's old map.

- [ ] **Step 4: No commit (DB state, not code)**

---

## Task 19: Frontend — update `Reputation` TS type

**Files:**
- Modify: `frontend/src/types/character.ts:139-145` (the `Reputation` interface)

- [ ] **Step 1: Replace the interface**

In `frontend/src/types/character.ts`, replace lines 139-145 (the `Reputation` interface) with:

```typescript
export interface Expansion {
  id: number
  name: string
  display_order: number
}

export interface Faction {
  id: number
  name: string
  parent_faction_id: number | null
  expansion: Expansion | null
}

export interface Reputation {
  faction_id: number
  faction_name: string
  standing: ReputationStanding
  value: number
  max: number
  faction: Faction | null
}
```

(Note: there is an existing `Faction` import from `./wow` higher in the file — that one is the *character's* faction enum, `'Alliance' | 'Horde'`. Rename one of them to disambiguate. The simpler change: rename the new one to `FactionGameData`.)

If a name collision is detected, use `FactionGameData` instead:

```typescript
export interface FactionGameData {
  id: number
  name: string
  parent_faction_id: number | null
  expansion: Expansion | null
}

export interface Reputation {
  faction_id: number
  faction_name: string
  standing: ReputationStanding
  value: number
  max: number
  faction: FactionGameData | null
}
```

- [ ] **Step 2: Verify the file still type-checks**

Run:
```bash
cd ../frontend
npx vue-tsc -b
```

Expected: no new errors.

- [ ] **Step 3: Commit**

Run:
```bash
git add src/types/character.ts
git commit -m "feat(plan-5): add Faction/Expansion/Reputation type extensions"
```

---

## Task 20: Frontend — drop `EXPANSION_BY_FACTION_ID` from `ReputationsList.vue`

**Files:**
- Modify: `frontend/src/components/character/ReputationsList.vue`

- [ ] **Step 1: Replace the `<script setup>` block**

Replace lines 39-92 (the `<script setup>` block) of `frontend/src/components/character/ReputationsList.vue` with:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Reputation, ReputationStanding } from '@/types/character'

const props = defineProps<{
  entries: Reputation[] | null
}>()

interface ExpansionGroup {
  label: string
  order: number
  entries: Reputation[]
}

function bucketOf(rep: Reputation): { label: string; order: number } {
  if (rep.faction?.expansion) {
    return {
      label: rep.faction.expansion.name,
      order: rep.faction.expansion.display_order,
    }
  }

  return { label: 'Legacy', order: 99 }
}

const groupedByExpansion = computed<ExpansionGroup[]>(() => {
  if (!props.entries) return []
  const byLabel = new Map<string, ExpansionGroup>()
  for (const rep of props.entries) {
    const { label, order } = bucketOf(rep)
    let group = byLabel.get(label)
    if (!group) {
      group = { label, order, entries: [] }
      byLabel.set(label, group)
    }
    group.entries.push(rep)
  }
  return Array.from(byLabel.values())
    .sort((a, b) => a.order - b.order)
    .map((g) => ({
      ...g,
      entries: [...g.entries].sort((a, b) => a.faction_name.localeCompare(b.faction_name)),
    }))
})

function standingBorderClass(standing: ReputationStanding): string {
  return {
    hated: 'border-red-700',
    hostile: 'border-red-500',
    unfriendly: 'border-orange-500',
    neutral: 'border-gray-400',
    friendly: 'border-emerald-500',
    honored: 'border-blue-400',
    revered: 'border-purple-400',
    exalted: 'border-amber-400',
  }[standing]
}

function standingBadgeClass(standing: ReputationStanding): string {
  return {
    hated: 'badge-error',
    hostile: 'badge-error',
    unfriendly: 'badge-warning',
    neutral: 'badge-ghost',
    friendly: 'badge-success',
    honored: 'badge-info',
    revered: 'badge-secondary',
    exalted: 'badge-warning text-amber-700',
  }[standing]
}

function standingProgressClass(standing: ReputationStanding): string {
  return {
    hated: 'progress-error',
    hostile: 'progress-error',
    unfriendly: 'progress-warning',
    neutral: 'progress-info',
    friendly: 'progress-success',
    honored: 'progress-info',
    revered: 'progress-secondary',
    exalted: 'progress-warning',
  }[standing]
}
</script>
```

The only structural change vs. the previous version: `bucketOf` now reads `rep.faction?.expansion` instead of an inlined `EXPANSION_BY_FACTION_ID` lookup. The `Legacy` bucket fallback for rows without `faction.expansion` preserves today's behavior.

- [ ] **Step 2: Type-check**

Run:
```bash
npx vue-tsc -b
```

Expected: no errors.

- [ ] **Step 3: Build the frontend**

Run:
```bash
npm run build
```

Expected: build green.

- [ ] **Step 4: Commit**

Run:
```bash
git add src/components/character/ReputationsList.vue
git commit -m "feat(plan-5): drop hardcoded faction→expansion map; read from API"
```

---

## Task 21: Manual smoke test in dev

**Files:** none (manual)

- [ ] **Step 1: Start the dev stack**

In one terminal:
```bash
cd backend
composer dev
```

In another:
```bash
cd frontend
npm run dev
```

- [ ] **Step 2: Look up a character with reputations populated**

In the browser, navigate to a character that has Plan 4 reputations data (e.g., the test character used during Plan 4 ramp). Click the **Reputations** tab.

Expected: faction groups render under their expansion headers exactly as before, but the data now comes from the BE join (verify by opening Network tab and inspecting `/api/v1/characters/...` payload — `data.reputations[0].faction.expansion.name` should be present).

- [ ] **Step 3: Visually verify the Legacy bucket**

If the test character has factions outside the seeded 11-entry map (most older-expansion factions will be), they should bucket as `Legacy` at the bottom. Same as the old behavior.

- [ ] **Step 4: No commit (manual step only)**

---

## Task 22: Final BE + FE verification

**Files:** none (test runs only)

- [ ] **Step 1: Full BE test suite**

Run:
```bash
cd backend
composer test
```

Expected: all tests pass. Plan 4's existing 51 tests + Plan 5 factions' new tests (≈14 new: 8 mapper, 5 client, 3 endpoint, 3 command) all green.

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

## Task 23: Update CLAUDE.md (backend) with the new slice notes

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Add the slice bullet**

Open `backend/CLAUDE.md`. Find the "## Architecture > ### Blizzard Module" bullet list (the section that already lists "**Stats slice.**", "**Titles slice.**", "**Reputations slice.**", "**Achievements slice (Plan 4).**", "**Collections slice (Plan 4).**"). Append a new bullet:

```markdown
- **Game-data factions resolver (Plan 5).** `game_data_expansions` (11 rows, seeded by `GameDataExpansionSeeder`) and `game_data_factions` (synced from `/data/wow/reputation-faction/{index,id}` in the **`static-{region}`** namespace by `php artisan blizzard:sync-game-data factions`, scheduled weekly) hydrate `CharacterReputation` via a new `faction()` `belongsTo` relation. `ReputationResource` exposes `faction.expansion.{id,name,display_order}` via `whenLoaded`. The faction → expansion mapping is a static `FACTION_TO_EXPANSION` array on `GameDataFactionMapper` (Blizzard does not expose expansion on the faction endpoint); 11 entries today, extend per patch. No feature flag — the eager-load is unconditional, and missing `game_data_factions` rows simply fall through to the FE's `Legacy` bucket (preserving pre-Plan-5 behavior).
```

- [ ] **Step 2: Clarify the namespace note**

In the same CLAUDE.md, find the line that reads (around "Namespace per client."):

```
**Namespace per client.** `BlizzardProfileClient` sends `namespace=profile-{region}`; `BlizzardGameDataClient` sends `namespace=dynamic-{region}`. ... Game-data endpoints (season index, item, playable-race, etc.) require `dynamic-{region}`.
```

Update the last sentence to:

```
Game-data **dynamic** endpoints (mythic-keystone seasons, leaderboards) use `namespace=dynamic-{region}`; game-data **static** endpoints (achievements, mounts, titles, reputation-factions, talent trees, items, playable-race) use `namespace=static-{region}` — see `BlizzardGameDataClient::getTalentTree()` and `getFactionIndex()/getFaction()` for the static-namespace pattern (bypasses `request()` and calls `Http` directly).
```

- [ ] **Step 3: Commit**

Run:
```bash
git add CLAUDE.md
git commit -m "docs(plan-5): document factions slice + clarify static vs dynamic game-data namespaces"
```

---

## Task 24: Final review pass

**Files:** none (review only)

- [ ] **Step 1: Confirm all commits land on the feature branch**

Run:
```bash
git log master..HEAD --oneline
```

Expected: ~13 commits ranging from "feat(plan-5): add game_data_expansions table" to "docs(plan-5): document factions slice".

- [ ] **Step 2: Re-run the full suite**

Run:
```bash
composer test && (cd ../frontend && npx vue-tsc -b && npm run build)
```

Expected: all green.

- [ ] **Step 3: Push the branch**

Run:
```bash
git push -u origin feature/plan-5-game-data-resolver
```

- [ ] **Step 4: Open the PR**

Open a PR from `feature/plan-5-game-data-resolver` into `master`. Title: `Plan 5 — factions slice (game-data resolver foundation)`. Body should reference the spec at `backend/docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` and this plan.

The branch will continue to receive commits for the next four sub-slices (titles, mounts, achievements, cleanup) before final merge to master, per the spec's single-feature-branch model. The PR can either land per-slice (preferred for review hygiene) or hold open until cleanup is done — operator's call.

---

## Notes on follow-up sub-slices

The four downstream Plan 5 plans (titles, mounts, achievements, cleanup) reuse the patterns established here:

- **Client method shape** — `getXxxIndex()` + `getXxx(int $id)`, both `static-{region}` namespace, both wrapped in `Cache::remember(..., $game_data_cache_ttl, ...)`.
- **Mapper shape** — `mapDetail(?array $data): ?Xxx` returning a typed DTO; `extractIndexIds(?array $data): array` extracting the ID list from the index response.
- **Artisan command** — extend `SyncGameData::handle()` with a new `match` arm + a `syncXxx` private method following the `syncFactions` template.
- **Eager-load** — append the new relation to `CharacterController`'s `loadMissing(...)` list.
- **Resource** — add `whenLoaded('gameData')` (or `whenLoaded('faction')` for reputations) to the existing per-character resource (`TitleResource`, `MountResource`, etc.).
- **FE type** — add the nested `gameData` shape to the corresponding type in `frontend/src/types/character.ts`; consume in the relevant tab/list component.

Each follow-up plan ships in the same commit cluster style on `feature/plan-5-game-data-resolver`. The cleanup slice (5th and last) drops the Plan 4 `BLIZZARD_SYNC_*_ENABLED` flags + their `if(config(...))` guards in `SyncCharacterData::handle()` once Plan 4 ramp is verified in production.
