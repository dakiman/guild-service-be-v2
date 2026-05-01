# Plan A — PvE Game-Data Resolver Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-05-01-pve-tab-redesign-design.md` (Plan A in §3 — BE PvE game-data resolver). Pay closest attention to §2.5 (schema), §2.6 (API endpoints), §3 (slicing), §4 (acceptance).

**Goal:** Stand up the BE-only PvE game-data resolver that supplies (a) raid-instance metadata + boss roster + media URLs (so the FE can render the headline `X/Y` denominator and boss portraits), and (b) mythic-keystone dungeon metadata + media + the season's affixes (so the FE can render dungeon icons and affix icons in the new Mythic+ section). Pure BE, no FE changes — Plan B will consume these endpoints.

**Architecture:** Four new tables (`game_data_raid_instances`, `game_data_raid_encounters`, `game_data_mythic_keystone_dungeons`, `game_data_keystone_affixes`) populated by a new `pve` arm of the existing `blizzard:sync-game-data` Artisan command. Each Blizzard journal-instance and dungeon detail call is paired with a `media/` call to capture the portrait/icon/background URL. Two new public endpoints under a new `GameDataController` expose the data: `GET /api/v1/game-data/raid-instances?expansion=current|all` (current scoped to the latest `game_data_expansions.display_order=1` row, with `?expansion=all` returning every expansion) and `GET /api/v1/game-data/mythic-keystone-dungeons?season=current` (dungeons for the current season, with affixes piggybacked into the same response). Both endpoints emit `Cache-Control: public, max-age=3600` per spec §2.6. The `expansion_id` foreign key on `game_data_raid_instances` reuses the `game_data_expansions` table and `GameDataExpansionSeeder` already shipped in the Plan-5 factions slice.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL.

**Out of scope (deferred or other plans):** All FE work (covered in Plan B). iLvl-at-kill, first-kill date, leaderboard ranks (spec §2.2). Tier abstraction (spec §2.3). Per-dungeon score breakdown / Tyrannical-Fortified split (spec §2.9). Backfilling existing characters with new game-data joins (spec §2.9 — the FE will join client-side).

**Sequencing:** First plan of two cut from the PvE-tab spec. Branch `feature/pve-tab-redesign` cuts off `master` after Plan 5 cleanup merged 2026-04-30; the four `game_data_*` tables sit alongside the Plan 5 ones. Plan B (FE rebuild) starts only after this plan merges.

**Deploy-ready at the end of:** this plan, after running `php artisan migrate && php artisan blizzard:sync-game-data pve` in each environment (the `pve` arm extends an existing command — no new Artisan signature). The two endpoints return populated JSON immediately afterward; FE continues rendering the legacy PvE tab unchanged until Plan B lands.

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
git checkout -b feature/pve-tab-redesign
```

Expected: clean working tree, branch created off the post-Plan-5-cleanup `master`.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: most recent commits include the Plan 5 cleanup merge (2026-04-30). The new spec `docs/superpowers/specs/2026-05-01-pve-tab-redesign-design.md` should be present.

- [ ] **Step 3: Confirm prior tables exist**

Run:
```bash
php artisan tinker --execute="
dump(Schema::hasTable('game_data_expansions'));
dump(Schema::hasTable('game_data_factions'));
"
```

Expected: both `true`. The `game_data_expansions` table is the FK target for `game_data_raid_instances` we are about to create.

---

## Task 2: Migration — `game_data_raid_instances` table

**Files:**
- Create: `database/migrations/2026_05_01_100001_create_game_data_raid_instances_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_05_01_100001_create_game_data_raid_instances_table.php`:

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
        Schema::create('game_data_raid_instances', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard journal-instance id
            $table->string('name', 200);
            $table->unsignedSmallInteger('expansion_id')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->text('media_url')->nullable();
            $table->timestamps();

            $table->foreign('expansion_id')
                ->references('id')
                ->on('game_data_expansions')
                ->nullOnDelete();

            $table->index('expansion_id');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_raid_instances');
    }
};
```

- [ ] **Step 2: Run the migration locally**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors. `game_data_raid_instances` table exists with FK to `game_data_expansions`.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_raid_instances'));"
```

Expected: `["id", "name", "expansion_id", "display_order", "media_url", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_05_01_100001_create_game_data_raid_instances_table.php
git commit -m "feat(pve): add game_data_raid_instances table"
```

---

## Task 3: Migration — `game_data_raid_encounters` table

**Files:**
- Create: `database/migrations/2026_05_01_100002_create_game_data_raid_encounters_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_05_01_100002_create_game_data_raid_encounters_table.php`:

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
        Schema::create('game_data_raid_encounters', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard journal-encounter id
            $table->unsignedInteger('raid_instance_id');
            $table->string('name', 200);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedInteger('creature_display_id')->nullable();
            $table->text('portrait_url')->nullable();
            $table->timestamps();

            $table->foreign('raid_instance_id')
                ->references('id')
                ->on('game_data_raid_instances')
                ->cascadeOnDelete();

            $table->index('raid_instance_id');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_raid_encounters');
    }
};
```

- [ ] **Step 2: Run the migration**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors. FK enforced.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_raid_encounters'));"
```

Expected: `["id", "raid_instance_id", "name", "display_order", "creature_display_id", "portrait_url", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_05_01_100002_create_game_data_raid_encounters_table.php
git commit -m "feat(pve): add game_data_raid_encounters table"
```

---

## Task 4: Migration — `game_data_mythic_keystone_dungeons` table

**Files:**
- Create: `database/migrations/2026_05_01_100003_create_game_data_mythic_keystone_dungeons_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_05_01_100003_create_game_data_mythic_keystone_dungeons_table.php`:

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
        Schema::create('game_data_mythic_keystone_dungeons', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard mythic-keystone dungeon id
            $table->string('name', 200);
            $table->text('media_url')->nullable();
            $table->unsignedInteger('journal_instance_id')->nullable();
            $table->timestamps();

            // Not FK-constrained: dungeons may reference a journal-instance that
            // is not tracked in game_data_raid_instances (older expansions whose
            // raids we don't sync). Treat as a soft join key.
            $table->index('journal_instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_mythic_keystone_dungeons');
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
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_mythic_keystone_dungeons'));"
```

Expected: `["id", "name", "media_url", "journal_instance_id", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_05_01_100003_create_game_data_mythic_keystone_dungeons_table.php
git commit -m "feat(pve): add game_data_mythic_keystone_dungeons table"
```

---

## Task 5: Migration — `game_data_keystone_affixes` table

**Files:**
- Create: `database/migrations/2026_05_01_100004_create_game_data_keystone_affixes_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_05_01_100004_create_game_data_keystone_affixes_table.php`:

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
        Schema::create('game_data_keystone_affixes', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard keystone-affix id
            $table->string('name', 100);
            $table->text('icon_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_keystone_affixes');
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
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_keystone_affixes'));"
```

Expected: `["id", "name", "icon_url", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_05_01_100004_create_game_data_keystone_affixes_table.php
git commit -m "feat(pve): add game_data_keystone_affixes table"
```

---

## Task 6: Eloquent model — `GameDataRaidInstance`

**Files:**
- Create: `app/Models/GameDataRaidInstance.php`

- [ ] **Step 1: Write the model**

Create `backend/app/Models/GameDataRaidInstance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDataRaidInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'expansion_id',
        'display_order',
        'media_url',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'expansion_id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(GameDataExpansion::class, 'expansion_id');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(GameDataRaidEncounter::class, 'raid_instance_id')
            ->orderBy('display_order');
    }
}
```

- [ ] **Step 2: Commit (deferred until model siblings written — see Task 9)**

(No commit yet; the four model files are committed as a unit in Task 9 to keep the model batch atomic.)

---

## Task 7: Eloquent model — `GameDataRaidEncounter`

**Files:**
- Create: `app/Models/GameDataRaidEncounter.php`

- [ ] **Step 1: Write the model**

Create `backend/app/Models/GameDataRaidEncounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataRaidEncounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'raid_instance_id',
        'name',
        'display_order',
        'creature_display_id',
        'portrait_url',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'raid_instance_id' => 'integer',
            'display_order' => 'integer',
            'creature_display_id' => 'integer',
        ];
    }

    public function raidInstance(): BelongsTo
    {
        return $this->belongsTo(GameDataRaidInstance::class, 'raid_instance_id');
    }
}
```

- [ ] **Step 2: No commit yet** (see Task 9)

---

## Task 8: Eloquent models — `GameDataMythicKeystoneDungeon` and `GameDataKeystoneAffix`

**Files:**
- Create: `app/Models/GameDataMythicKeystoneDungeon.php`
- Create: `app/Models/GameDataKeystoneAffix.php`

- [ ] **Step 1: Write `GameDataMythicKeystoneDungeon`**

Create `backend/app/Models/GameDataMythicKeystoneDungeon.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataMythicKeystoneDungeon extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'media_url',
        'journal_instance_id',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'journal_instance_id' => 'integer',
        ];
    }

    /**
     * Soft join key — not a FK constraint (older-expansion dungeons may
     * reference a journal_instance the operator did not sync).
     */
    public function raidInstance(): BelongsTo
    {
        return $this->belongsTo(GameDataRaidInstance::class, 'journal_instance_id');
    }
}
```

- [ ] **Step 2: Write `GameDataKeystoneAffix`**

Create `backend/app/Models/GameDataKeystoneAffix.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameDataKeystoneAffix extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'icon_url',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }
}
```

- [ ] **Step 3: No commit yet** (see Task 9)

---

## Task 9: Smoke-test all four models, then commit

**Files:** none (verification + commit)

- [ ] **Step 1: Smoke-test the relations in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataRaidInstance;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataKeystoneAffix;
\$r = GameDataRaidInstance::create(['id' => 1296, 'name' => 'Test Raid', 'expansion_id' => 1, 'display_order' => 1, 'media_url' => 'https://example/r.png']);
\$e = GameDataRaidEncounter::create(['id' => 2900, 'raid_instance_id' => 1296, 'name' => 'Boss A', 'display_order' => 0, 'creature_display_id' => 999, 'portrait_url' => 'https://example/b.png']);
\$d = GameDataMythicKeystoneDungeon::create(['id' => 503, 'name' => 'Test Dungeon', 'media_url' => 'https://example/d.png', 'journal_instance_id' => 1296]);
\$a = GameDataKeystoneAffix::create(['id' => 9, 'name' => 'Tyrannical', 'icon_url' => 'https://example/a.png']);
dump(\$r->encounters->first()->name);
dump(\$e->raidInstance->name);
dump(\$d->raidInstance->name);
dump(\$a->name);
\$e->delete(); \$r->delete(); \$d->delete(); \$a->delete();
"
```

Expected: dumps print `"Boss A"`, `"Test Raid"`, `"Test Raid"`, `"Tyrannical"` and rows clean up.

- [ ] **Step 2: Commit the model batch**

Run:
```bash
git add app/Models/GameDataRaidInstance.php app/Models/GameDataRaidEncounter.php app/Models/GameDataMythicKeystoneDungeon.php app/Models/GameDataKeystoneAffix.php
git commit -m "feat(pve): add raid/dungeon/affix Eloquent models"
```

---

## Task 10: DTO — `GameDataRaidInstance`

**Files:**
- Create: `app/Blizzard/DTO/GameDataRaidInstance.php`

- [ ] **Step 1: Write the DTO**

Create `backend/app/Blizzard/DTO/GameDataRaidInstance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataRaidInstance
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $expansionId,
        public int $displayOrder,
        public ?string $mediaUrl,
        /** @var int[] encounter IDs the instance exposes (used for the encounter sync fan-out) */
        public array $encounterIds,
    ) {}
}
```

- [ ] **Step 2: No commit yet** (DTO batch committed in Task 13)

---

## Task 11: DTO — `GameDataRaidEncounter`

**Files:**
- Create: `app/Blizzard/DTO/GameDataRaidEncounter.php`

- [ ] **Step 1: Write the DTO**

Create `backend/app/Blizzard/DTO/GameDataRaidEncounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataRaidEncounter
{
    public function __construct(
        public int $id,
        public int $raidInstanceId,
        public string $name,
        public int $displayOrder,
        public ?int $creatureDisplayId,
        public ?string $portraitUrl,
    ) {}
}
```

- [ ] **Step 2: No commit yet** (DTO batch committed in Task 13)

---

## Task 12: DTOs — `GameDataMythicKeystoneDungeon` and `GameDataKeystoneAffix`

**Files:**
- Create: `app/Blizzard/DTO/GameDataMythicKeystoneDungeon.php`
- Create: `app/Blizzard/DTO/GameDataKeystoneAffix.php`

- [ ] **Step 1: Write `GameDataMythicKeystoneDungeon` DTO**

Create `backend/app/Blizzard/DTO/GameDataMythicKeystoneDungeon.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataMythicKeystoneDungeon
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $mediaUrl,
        public ?int $journalInstanceId,
    ) {}
}
```

- [ ] **Step 2: Write `GameDataKeystoneAffix` DTO**

Create `backend/app/Blizzard/DTO/GameDataKeystoneAffix.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataKeystoneAffix
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $iconUrl,
    ) {}
}
```

- [ ] **Step 3: No commit yet** (see Task 13)

---

## Task 13: Commit the DTO batch

**Files:** none (commit only)

- [ ] **Step 1: Commit**

Run:
```bash
git add app/Blizzard/DTO/GameDataRaidInstance.php app/Blizzard/DTO/GameDataRaidEncounter.php app/Blizzard/DTO/GameDataMythicKeystoneDungeon.php app/Blizzard/DTO/GameDataKeystoneAffix.php
git commit -m "feat(pve): add PvE game-data DTOs (raid instance/encounter/dungeon/affix)"
```

---

## Task 14: Mapper — `GameDataRaidInstanceMapper` (test-first)

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataRaidInstanceMapperTest.php`
- Create: `app/Blizzard/Mappers/GameDataRaidInstanceMapper.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataRaidInstanceMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataRaidInstanceMapper;
use PHPUnit\Framework\TestCase;

class GameDataRaidInstanceMapperTest extends TestCase
{
    private GameDataRaidInstanceMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataRaidInstanceMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 1296,
                'name' => 'Liberation of Undermine',
                'expansion' => ['id' => 1],
                'order_index' => 5,
                'encounters' => [
                    ['id' => 2902, 'name' => 'Vexie'],
                    ['id' => 2917, 'name' => 'Cauldron of Carnage'],
                ],
            ],
            mediaUrl: 'https://render.worldofwarcraft.com/eu/icons/lou.jpg',
        );

        $this->assertSame(1296, $dto->id);
        $this->assertSame('Liberation of Undermine', $dto->name);
        $this->assertSame(1, $dto->expansionId);
        $this->assertSame(5, $dto->displayOrder);
        $this->assertSame('https://render.worldofwarcraft.com/eu/icons/lou.jpg', $dto->mediaUrl);
        $this->assertSame([2902, 2917], $dto->encounterIds);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: ['id' => 9, 'name' => 'Bare Raid'],
            mediaUrl: null,
        );

        $this->assertSame(9, $dto->id);
        $this->assertNull($dto->expansionId);
        $this->assertSame(0, $dto->displayOrder);
        $this->assertNull($dto->mediaUrl);
        $this->assertSame([], $dto->encounterIds);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'instances' => [
                ['id' => 1296, 'name' => 'A'],
                ['id' => 1273, 'name' => 'B'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([1296, 1273], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }

    public function test_extract_media_url_from_media_response(): void
    {
        $url = $this->mapper->extractMediaUrl([
            'assets' => [
                ['key' => 'tile', 'value' => 'https://example/raid.jpg'],
            ],
        ]);

        $this->assertSame('https://example/raid.jpg', $url);
    }

    public function test_extract_media_url_returns_null_when_no_assets(): void
    {
        $this->assertNull($this->mapper->extractMediaUrl(['assets' => []]));
        $this->assertNull($this->mapper->extractMediaUrl(null));
    }
}
```

- [ ] **Step 2: Run the test, confirm it fails (mapper class does not exist yet)**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataRaidInstanceMapperTest.php
```

Expected: `Class "App\Blizzard\Mappers\GameDataRaidInstanceMapper" not found`.

- [ ] **Step 3: Implement the mapper**

Create `backend/app/Blizzard/Mappers/GameDataRaidInstanceMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataRaidInstance;

class GameDataRaidInstanceMapper
{
    /**
     * Map a Blizzard /data/wow/journal-instance/{id} response (plus the
     * companion media response) to a GameDataRaidInstance DTO.
     *
     * Detail response shape (relevant fields):
     *   { id, name, expansion: { id }, order_index, encounters: [{ id, name }, ...] }
     *
     * Media response shape:
     *   { assets: [{ key: "tile" | "...", value: "<url>" }, ...] }
     * The first asset's `value` is the raid background image; we take the
     * first assets entry unconditionally because Blizzard typically only
     * emits one for journal-instance media.
     */
    public function mapDetail(?array $detail, ?string $mediaUrl): ?GameDataRaidInstance
    {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        $encounterIds = [];
        foreach ($detail['encounters'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $encounterIds[] = (int) $entry['id'];
            }
        }

        return new GameDataRaidInstance(
            id: (int) $detail['id'],
            name: (string) ($detail['name'] ?? 'Unknown'),
            expansionId: isset($detail['expansion']['id'])
                ? (int) $detail['expansion']['id']
                : null,
            displayOrder: isset($detail['order_index'])
                ? (int) $detail['order_index']
                : 0,
            mediaUrl: $mediaUrl,
            encounterIds: $encounterIds,
        );
    }

    /**
     * Extract instance IDs from a /data/wow/journal-instance/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['instances'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }

    /**
     * Pull the first asset URL out of a /data/wow/media/journal-instance/{id}
     * response. Returns null if no assets or input is null.
     */
    public function extractMediaUrl(?array $media): ?string
    {
        if ($media === null) {
            return null;
        }

        foreach ($media['assets'] ?? [] as $asset) {
            if (isset($asset['value']) && is_string($asset['value']) && $asset['value'] !== '') {
                return $asset['value'];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Re-run the test, confirm it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataRaidInstanceMapperTest.php
```

Expected: 8 tests pass.

- [ ] **Step 5: Commit**

Run:
```bash
git add app/Blizzard/Mappers/GameDataRaidInstanceMapper.php tests/Unit/Blizzard/Mappers/GameDataRaidInstanceMapperTest.php
git commit -m "feat(pve): add GameDataRaidInstanceMapper with 8-case test coverage"
```

---

## Task 15: Mapper — `GameDataRaidEncounterMapper` (test-first)

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataRaidEncounterMapperTest.php`
- Create: `app/Blizzard/Mappers/GameDataRaidEncounterMapper.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataRaidEncounterMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataRaidEncounterMapper;
use PHPUnit\Framework\TestCase;

class GameDataRaidEncounterMapperTest extends TestCase
{
    private GameDataRaidEncounterMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataRaidEncounterMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 2902,
                'name' => 'Vexie and the Geargrinders',
                'instance' => ['id' => 1296],
                'creature_display' => ['id' => 109501],
                'order_index' => 0,
            ],
            portraitUrl: 'https://render.worldofwarcraft.com/eu/npcs/zoom/creature-display-109501.jpg',
            fallbackInstanceId: 1296,
            fallbackOrder: 0,
        );

        $this->assertSame(2902, $dto->id);
        $this->assertSame(1296, $dto->raidInstanceId);
        $this->assertSame('Vexie and the Geargrinders', $dto->name);
        $this->assertSame(0, $dto->displayOrder);
        $this->assertSame(109501, $dto->creatureDisplayId);
        $this->assertSame('https://render.worldofwarcraft.com/eu/npcs/zoom/creature-display-109501.jpg', $dto->portraitUrl);
    }

    public function test_falls_back_when_instance_and_order_are_missing(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 2917,
                'name' => 'Cauldron of Carnage',
            ],
            portraitUrl: null,
            fallbackInstanceId: 1296,
            fallbackOrder: 4,
        );

        $this->assertSame(1296, $dto->raidInstanceId, 'falls back to the parent instance id passed in');
        $this->assertSame(4, $dto->displayOrder, 'falls back to the supplied order');
        $this->assertNull($dto->creatureDisplayId);
        $this->assertNull($dto->portraitUrl);
    }

    public function test_extracts_creature_display_id_from_creature_displays_array(): void
    {
        // Some Blizzard responses use an array `creature_displays` instead of
        // singular `creature_display`. Mapper should accept either; first
        // entry wins.
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 1,
                'name' => 'X',
                'creature_displays' => [
                    ['id' => 200001],
                    ['id' => 200002],
                ],
            ],
            portraitUrl: null,
            fallbackInstanceId: 0,
            fallbackOrder: 0,
        );

        $this->assertSame(200001, $dto->creatureDisplayId);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null, 0, 0));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null, 0, 0));
    }

    public function test_extract_media_url_returns_zoom_asset(): void
    {
        $url = $this->mapper->extractMediaUrl([
            'assets' => [
                ['key' => 'zoom', 'value' => 'https://example/zoom.jpg'],
            ],
        ]);

        $this->assertSame('https://example/zoom.jpg', $url);
    }

    public function test_extract_media_url_returns_null_when_assets_missing(): void
    {
        $this->assertNull($this->mapper->extractMediaUrl(null));
        $this->assertNull($this->mapper->extractMediaUrl(['assets' => []]));
    }
}
```

- [ ] **Step 2: Run the test, confirm it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataRaidEncounterMapperTest.php
```

Expected: `Class "App\Blizzard\Mappers\GameDataRaidEncounterMapper" not found`.

- [ ] **Step 3: Implement the mapper**

Create `backend/app/Blizzard/Mappers/GameDataRaidEncounterMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataRaidEncounter;

class GameDataRaidEncounterMapper
{
    /**
     * Map a Blizzard /data/wow/journal-encounter/{id} response (plus the
     * companion creature-display media URL) to a GameDataRaidEncounter DTO.
     *
     * Detail response shape (relevant fields):
     *   {
     *     id, name,
     *     instance: { id },
     *     creature_display?: { id },
     *     creature_displays?: [{ id }, ...],
     *     order_index?: int,
     *   }
     *
     * The instance id and order can be missing on some responses — caller
     * supplies fallbacks (the parent instance id and the encounter's index
     * within the instance roster, respectively).
     */
    public function mapDetail(
        ?array $detail,
        ?string $portraitUrl,
        int $fallbackInstanceId,
        int $fallbackOrder,
    ): ?GameDataRaidEncounter {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        return new GameDataRaidEncounter(
            id: (int) $detail['id'],
            raidInstanceId: isset($detail['instance']['id'])
                ? (int) $detail['instance']['id']
                : $fallbackInstanceId,
            name: (string) ($detail['name'] ?? 'Unknown'),
            displayOrder: isset($detail['order_index'])
                ? (int) $detail['order_index']
                : $fallbackOrder,
            creatureDisplayId: $this->extractCreatureDisplayId($detail),
            portraitUrl: $portraitUrl,
        );
    }

    /**
     * Pull the first asset URL out of a
     * /data/wow/media/creature-display/{id} response.
     */
    public function extractMediaUrl(?array $media): ?string
    {
        if ($media === null) {
            return null;
        }

        foreach ($media['assets'] ?? [] as $asset) {
            if (isset($asset['value']) && is_string($asset['value']) && $asset['value'] !== '') {
                return $asset['value'];
            }
        }

        return null;
    }

    private function extractCreatureDisplayId(array $detail): ?int
    {
        if (isset($detail['creature_display']['id'])) {
            return (int) $detail['creature_display']['id'];
        }

        if (isset($detail['creature_displays'][0]['id'])) {
            return (int) $detail['creature_displays'][0]['id'];
        }

        return null;
    }
}
```

- [ ] **Step 4: Re-run the test, confirm it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataRaidEncounterMapperTest.php
```

Expected: 7 tests pass.

- [ ] **Step 5: Commit**

Run:
```bash
git add app/Blizzard/Mappers/GameDataRaidEncounterMapper.php tests/Unit/Blizzard/Mappers/GameDataRaidEncounterMapperTest.php
git commit -m "feat(pve): add GameDataRaidEncounterMapper with 7-case test coverage"
```

---

## Task 16: Mapper — `GameDataMythicKeystoneDungeonMapper` (test-first)

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapperTest.php`
- Create: `app/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapper.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper;
use PHPUnit\Framework\TestCase;

class GameDataMythicKeystoneDungeonMapperTest extends TestCase
{
    private GameDataMythicKeystoneDungeonMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataMythicKeystoneDungeonMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 503,
                'name' => 'Ara-Kara, City of Echoes',
                'map' => ['id' => 2293],
                'keystone_upgrades' => [],
            ],
            mediaUrl: 'https://example/arak.jpg',
            journalInstanceId: 1271,
        );

        $this->assertSame(503, $dto->id);
        $this->assertSame('Ara-Kara, City of Echoes', $dto->name);
        $this->assertSame('https://example/arak.jpg', $dto->mediaUrl);
        $this->assertSame(1271, $dto->journalInstanceId);
    }

    public function test_handles_missing_media_and_journal_instance(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: ['id' => 1, 'name' => 'Bare'],
            mediaUrl: null,
            journalInstanceId: null,
        );

        $this->assertNull($dto->mediaUrl);
        $this->assertNull($dto->journalInstanceId);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null, null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null, null));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'dungeons' => [
                ['id' => 503, 'name' => 'A'],
                ['id' => 504, 'name' => 'B'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([503, 504], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }

    public function test_extract_media_url(): void
    {
        $this->assertSame(
            'https://example/d.png',
            $this->mapper->extractMediaUrl([
                'assets' => [['key' => 'tile', 'value' => 'https://example/d.png']],
            ]),
        );
        $this->assertNull($this->mapper->extractMediaUrl(null));
    }
}
```

- [ ] **Step 2: Run the test, confirm it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapperTest.php
```

Expected: `Class "App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper" not found`.

- [ ] **Step 3: Implement the mapper**

Create `backend/app/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataMythicKeystoneDungeon;

class GameDataMythicKeystoneDungeonMapper
{
    /**
     * Map a Blizzard /data/wow/mythic-keystone/dungeon/{id} response (plus
     * the companion media URL and a journal-instance id resolved by the
     * caller from the season payload) to a GameDataMythicKeystoneDungeon DTO.
     *
     * Detail response shape (relevant fields):
     *   { id, name, map: { id }, keystone_upgrades: [...] }
     *
     * Note: Blizzard's mythic-keystone dungeon detail does NOT directly
     * expose `journal_instance` — the FE-side fallback portrait join uses
     * a value supplied by the sync command (typically resolved from the
     * season's `dungeons[].id` lookup table or hand-mapped per patch).
     */
    public function mapDetail(
        ?array $detail,
        ?string $mediaUrl,
        ?int $journalInstanceId,
    ): ?GameDataMythicKeystoneDungeon {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        return new GameDataMythicKeystoneDungeon(
            id: (int) $detail['id'],
            name: (string) ($detail['name'] ?? 'Unknown'),
            mediaUrl: $mediaUrl,
            journalInstanceId: $journalInstanceId,
        );
    }

    /**
     * Extract dungeon IDs from a /data/wow/mythic-keystone/dungeon/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['dungeons'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }

    /**
     * Pull the first asset URL from a /data/wow/media/...{dungeon-icon} response.
     * Note: Blizzard does not currently emit a media doc for keystone dungeons
     * — this method exists for symmetry with the raid/affix mappers and may
     * be wired in if/when Blizzard extends the API.
     */
    public function extractMediaUrl(?array $media): ?string
    {
        if ($media === null) {
            return null;
        }

        foreach ($media['assets'] ?? [] as $asset) {
            if (isset($asset['value']) && is_string($asset['value']) && $asset['value'] !== '') {
                return $asset['value'];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Re-run the test, confirm it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapperTest.php
```

Expected: 7 tests pass.

- [ ] **Step 5: Commit**

Run:
```bash
git add app/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapper.php tests/Unit/Blizzard/Mappers/GameDataMythicKeystoneDungeonMapperTest.php
git commit -m "feat(pve): add GameDataMythicKeystoneDungeonMapper with 7-case test coverage"
```

---

## Task 17: Mapper — `GameDataKeystoneAffixMapper` (test-first)

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataKeystoneAffixMapperTest.php`
- Create: `app/Blizzard/Mappers/GameDataKeystoneAffixMapper.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataKeystoneAffixMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataKeystoneAffixMapper;
use PHPUnit\Framework\TestCase;

class GameDataKeystoneAffixMapperTest extends TestCase
{
    private GameDataKeystoneAffixMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataKeystoneAffixMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: [
                'id' => 9,
                'name' => 'Tyrannical',
                'description' => 'Bosses have 30% more health.',
            ],
            iconUrl: 'https://render.worldofwarcraft.com/eu/icons/affix-9.jpg',
        );

        $this->assertSame(9, $dto->id);
        $this->assertSame('Tyrannical', $dto->name);
        $this->assertSame('https://render.worldofwarcraft.com/eu/icons/affix-9.jpg', $dto->iconUrl);
    }

    public function test_handles_missing_icon(): void
    {
        $dto = $this->mapper->mapDetail(
            detail: ['id' => 10, 'name' => 'Fortified'],
            iconUrl: null,
        );

        $this->assertNull($dto->iconUrl);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null, null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'No ID'], null));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'affixes' => [
                ['id' => 9, 'name' => 'Tyrannical'],
                ['id' => 10, 'name' => 'Fortified'],
                ['name' => 'no-id'],
            ],
        ]);

        $this->assertSame([9, 10], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }

    public function test_extract_icon_url_from_media_response(): void
    {
        $url = $this->mapper->extractIconUrl([
            'assets' => [
                ['key' => 'icon', 'value' => 'https://example/affix-9.jpg'],
            ],
        ]);

        $this->assertSame('https://example/affix-9.jpg', $url);
        $this->assertNull($this->mapper->extractIconUrl(null));
    }
}
```

- [ ] **Step 2: Run the test, confirm it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataKeystoneAffixMapperTest.php
```

Expected: `Class "App\Blizzard\Mappers\GameDataKeystoneAffixMapper" not found`.

- [ ] **Step 3: Implement the mapper**

Create `backend/app/Blizzard/Mappers/GameDataKeystoneAffixMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataKeystoneAffix;

class GameDataKeystoneAffixMapper
{
    /**
     * Map a Blizzard /data/wow/keystone-affix/{id} response (plus the
     * companion icon URL from /data/wow/media/keystone-affix/{id}) to a
     * GameDataKeystoneAffix DTO.
     */
    public function mapDetail(?array $detail, ?string $iconUrl): ?GameDataKeystoneAffix
    {
        if ($detail === null || ! isset($detail['id'])) {
            return null;
        }

        return new GameDataKeystoneAffix(
            id: (int) $detail['id'],
            name: (string) ($detail['name'] ?? 'Unknown'),
            iconUrl: $iconUrl,
        );
    }

    /**
     * Extract affix IDs from a /data/wow/keystone-affix/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['affixes'] ?? [] as $entry) {
            if (isset($entry['id'])) {
                $out[] = (int) $entry['id'];
            }
        }

        return $out;
    }

    public function extractIconUrl(?array $media): ?string
    {
        if ($media === null) {
            return null;
        }

        foreach ($media['assets'] ?? [] as $asset) {
            if (isset($asset['value']) && is_string($asset['value']) && $asset['value'] !== '') {
                return $asset['value'];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Re-run the test, confirm it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataKeystoneAffixMapperTest.php
```

Expected: 7 tests pass.

- [ ] **Step 5: Commit**

Run:
```bash
git add app/Blizzard/Mappers/GameDataKeystoneAffixMapper.php tests/Unit/Blizzard/Mappers/GameDataKeystoneAffixMapperTest.php
git commit -m "feat(pve): add GameDataKeystoneAffixMapper with 7-case test coverage"
```

---

## Task 18: Client methods — journal-instance index/detail + media

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Append the four new methods**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php`. After the existing `getAchievement(int $id)` method (which is currently the last method in the class), append:

```php
    /**
     * Fetch the journal-instance index from /data/wow/journal-instance/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * static-{region} namespace, 7-day cache — same precedent as
     * getFactionIndex() / getTalentTree().
     */
    public function getJournalInstanceIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:journal-instance-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/journal-instance/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single journal-instance by ID. Carries `expansion`, `order_index`
     * and `encounters: [{id, name}, ...]` — the encounter list is the boss
     * roster for the raid.
     */
    public function getJournalInstance(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:journal-instance:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/journal-instance/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/media/journal-instance/{id} — carries the raid
     * background image URL inside `assets[].value`.
     */
    public function getJournalInstanceMedia(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:journal-instance-media:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/media/journal-instance/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single journal-encounter by ID. Carries `creature_display.id`
     * (sometimes nested under `creature_displays[]`) — the FE uses this for
     * the boss portrait via the media/creature-display endpoint.
     */
    public function getJournalEncounter(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:journal-encounter:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/journal-encounter/{$id}");

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
git commit -m "feat(pve): add journal-instance/encounter/media client methods"
```

---

## Task 19: Client method tests — journal-instance + journal-encounter

**Files:**
- Modify: `tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`

- [ ] **Step 1: Append the new tests**

Open `backend/tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`. Append the following tests inside the test class (after the last existing achievement-related test):

```php
    public function test_get_journal_instance_index_uses_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/journal-instance/index?*' => Http::response([
                'instances' => [
                    ['id' => 1296, 'name' => 'Liberation of Undermine'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getJournalInstanceIndex();

        $this->assertSame(1296, $result['instances'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'journal-instance/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_journal_instance_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/journal-instance/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getJournalInstance(99999));
    }

    public function test_get_journal_instance_caches_within_ttl(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['id' => 1296, 'name' => 'X'], 200);
        });

        $client = $this->client();
        $client->getJournalInstance(1296);
        $client->getJournalInstance(1296);

        $this->assertSame(1, $callCount);
    }

    public function test_get_journal_instance_media_returns_assets(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/media/journal-instance/1296?*' => Http::response([
                'assets' => [['key' => 'tile', 'value' => 'https://example/r.jpg']],
            ], 200),
        ]);

        $result = $this->client()->getJournalInstanceMedia(1296);
        $this->assertSame('https://example/r.jpg', $result['assets'][0]['value']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'media/journal-instance/1296')
            && str_contains($req->url(), 'namespace=static-us'));
    }

    public function test_get_journal_encounter_returns_creature_display(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/journal-encounter/2902?*' => Http::response([
                'id' => 2902,
                'name' => 'Vexie',
                'creature_display' => ['id' => 109501],
            ], 200),
        ]);

        $result = $this->client()->getJournalEncounter(2902);
        $this->assertSame(109501, $result['creature_display']['id']);
    }
```

- [ ] **Step 2: Run the tests, confirm pass**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
```

Expected: all tests pass (existing + 5 new).

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
git commit -m "test(pve): cover journal-instance/encounter/media client methods"
```

---

## Task 20: Client methods — creature-display media + mythic-keystone dungeon index/detail + dungeon media + season

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Append the new methods**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php`. After the new `getJournalEncounter()` method added in Task 18, append:

```php
    /**
     * Fetch /data/wow/media/creature-display/{id} — boss portrait URL.
     */
    public function getCreatureDisplayMedia(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:creature-display-media:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/media/creature-display/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/mythic-keystone/dungeon/index — list of all
     * mythic-keystone dungeons (current expansion's pool only; older
     * expansions' dungeons drop out of the index when their seasons retire).
     */
    public function getMythicKeystoneDungeonIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:mk-dungeon-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mythic-keystone/dungeon/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/mythic-keystone/dungeon/{id} — name, map id,
     * keystone-upgrades. Note: this endpoint is in the **dynamic** namespace,
     * unlike the journal-instance endpoints, because mythic-keystone dungeons
     * rotate per season.
     */
    public function getMythicKeystoneDungeon(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:mk-dungeon:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mythic-keystone/dungeon/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/mythic-keystone/season/{id} — gives the season's
     * `dungeons: [{id, ...}]` list, used by the sync command to know which
     * dungeons belong to the current season.
     */
    public function getMythicKeystoneSeason(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:mk-season:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "dynamic-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mythic-keystone/season/{$id}");

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
git commit -m "feat(pve): add creature-display media + mythic-keystone dungeon/season client methods"
```

---

## Task 21: Client methods — keystone-affix index/detail + media

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Append the new methods**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php`. After the `getMythicKeystoneSeason` method added in Task 20, append:

```php
    /**
     * Fetch /data/wow/keystone-affix/index — the universe of keystone affixes.
     */
    public function getKeystoneAffixIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:keystone-affix-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/keystone-affix/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/keystone-affix/{id} — name + description.
     */
    public function getKeystoneAffix(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:keystone-affix:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/keystone-affix/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch /data/wow/media/keystone-affix/{id} — affix icon URL.
     */
    public function getKeystoneAffixMedia(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:keystone-affix-media:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/media/keystone-affix/{$id}");

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
git commit -m "feat(pve): add keystone-affix index/detail/media client methods"
```

---

## Task 22: Client method tests — dungeon, season, affix

**Files:**
- Modify: `tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`

- [ ] **Step 1: Append the new tests**

Open `backend/tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`. Append inside the test class:

```php
    public function test_get_mythic_keystone_dungeon_index_uses_dynamic_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/dungeon/index?*' => Http::response([
                'dungeons' => [['id' => 503, 'name' => 'Ara-Kara']],
            ], 200),
        ]);

        $result = $this->client()->getMythicKeystoneDungeonIndex();

        $this->assertSame(503, $result['dungeons'][0]['id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'mythic-keystone/dungeon/index')
            && str_contains($req->url(), 'namespace=dynamic-us'));
    }

    public function test_get_mythic_keystone_dungeon_returns_null_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/dungeon/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getMythicKeystoneDungeon(99999));
    }

    public function test_get_mythic_keystone_season_returns_dungeons_list(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/mythic-keystone/season/14?*' => Http::response([
                'id' => 14,
                'dungeons' => [['id' => 503], ['id' => 504]],
            ], 200),
        ]);

        $result = $this->client()->getMythicKeystoneSeason(14);
        $this->assertSame(2, count($result['dungeons']));
    }

    public function test_get_keystone_affix_index_uses_static_namespace(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/keystone-affix/index?*' => Http::response([
                'affixes' => [['id' => 9, 'name' => 'Tyrannical']],
            ], 200),
        ]);

        $result = $this->client()->getKeystoneAffixIndex();
        $this->assertSame(9, $result['affixes'][0]['id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'keystone-affix/index')
            && str_contains($req->url(), 'namespace=static-us'));
    }

    public function test_get_keystone_affix_caches_within_ttl(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['id' => 9, 'name' => 'Tyrannical'], 200);
        });

        $client = $this->client();
        $client->getKeystoneAffix(9);
        $client->getKeystoneAffix(9);

        $this->assertSame(1, $callCount);
    }

    public function test_get_keystone_affix_media_returns_icon(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/media/keystone-affix/9?*' => Http::response([
                'assets' => [['key' => 'icon', 'value' => 'https://example/affix-9.jpg']],
            ], 200),
        ]);

        $result = $this->client()->getKeystoneAffixMedia(9);
        $this->assertSame('https://example/affix-9.jpg', $result['assets'][0]['value']);
    }

    public function test_get_creature_display_media_returns_zoom_url(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/media/creature-display/109501?*' => Http::response([
                'assets' => [['key' => 'zoom', 'value' => 'https://example/zoom.jpg']],
            ], 200),
        ]);

        $result = $this->client()->getCreatureDisplayMedia(109501);
        $this->assertSame('https://example/zoom.jpg', $result['assets'][0]['value']);
    }
```

- [ ] **Step 2: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
git commit -m "test(pve): cover keystone-dungeon/season/affix/creature-display client methods"
```

---

## Task 23: Extend `SyncGameData` Artisan command — `pve` arm

**Files:**
- Modify: `app/Console/Commands/SyncGameData.php`

- [ ] **Step 1: Update the signature description**

Open `backend/app/Console/Commands/SyncGameData.php`. Replace the `$signature` line:

```php
    protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements|pve; omit for all}';
```

(adds `pve` to the doc list — runtime behavior is determined by the `match` arms below).

- [ ] **Step 2: Update the description**

Replace the `$description` line:

```php
    protected $description = 'Sync static reference data (factions/titles/mounts/achievements/pve) from Blizzard Game Data API into game_data_* tables';
```

- [ ] **Step 3: Inject the four new mappers**

Update the constructor list inside `handle(...)`. Replace the entire `handle` signature with:

```php
    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
        GameDataTitleMapper $titleMapper,
        GameDataMountMapper $mountMapper,
        GameDataAchievementCategoryMapper $achievementCategoryMapper,
        GameDataAchievementMapper $achievementMapper,
        \App\Blizzard\Mappers\GameDataRaidInstanceMapper $raidInstanceMapper,
        \App\Blizzard\Mappers\GameDataRaidEncounterMapper $raidEncounterMapper,
        \App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper $dungeonMapper,
        \App\Blizzard\Mappers\GameDataKeystoneAffixMapper $affixMapper,
    ): int {
```

(Use FQCN inline rather than adding `use` statements — keeps the diff narrow. The existing `use` block at the top of the file already imports the older mappers; importers may relocate later if Pint complains, but bare-FQCN is acceptable per project style as evidenced by `SyncCharacterData::handle()`'s existing usage.)

Then update the default-resource list and the `match` arms. Replace the body of `handle(...)` (after the constructor params close):

```php
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'titles', 'mounts', 'achievements', 'pve']
            : [$resource];

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                'mounts' => $this->syncMounts($client, $mountMapper),
                'achievements' => $this->syncAchievements($client, $achievementCategoryMapper, $achievementMapper),
                'pve' => $this->syncPve($client, $raidInstanceMapper, $raidEncounterMapper, $dungeonMapper, $affixMapper),
                default => $this->error("Unknown resource: {$r}") || self::FAILURE,
            };
        }

        return self::SUCCESS;
    }
```

- [ ] **Step 4: Add the `syncPve` private method**

At the bottom of the class, before the closing `}`, append:

```php
    /**
     * Sync the four PvE game-data tables. Sequence:
     *  1. Raid instances + their encounter rosters from the journal-instance
     *     family of endpoints. Encounters are fanned out per-instance with
     *     the instance id passed as the encounter's parent (the encounter
     *     detail response sometimes omits `instance.id`).
     *  2. Mythic-keystone dungeons (current season scope) — uses the season's
     *     `dungeons` list to know which IDs to sync, plus the dungeon-index
     *     for fields the season payload doesn't carry.
     *  3. Keystone affixes (full universe; ~12-16 rows) with their icons.
     */
    private function syncPve(
        BlizzardGameDataClient $client,
        \App\Blizzard\Mappers\GameDataRaidInstanceMapper $raidInstanceMapper,
        \App\Blizzard\Mappers\GameDataRaidEncounterMapper $raidEncounterMapper,
        \App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper $dungeonMapper,
        \App\Blizzard\Mappers\GameDataKeystoneAffixMapper $affixMapper,
    ): void {
        $this->syncRaids($client, $raidInstanceMapper, $raidEncounterMapper);
        $this->syncMythicKeystoneDungeons($client, $dungeonMapper);
        $this->syncKeystoneAffixes($client, $affixMapper);
    }

    private function syncRaids(
        BlizzardGameDataClient $client,
        \App\Blizzard\Mappers\GameDataRaidInstanceMapper $instanceMapper,
        \App\Blizzard\Mappers\GameDataRaidEncounterMapper $encounterMapper,
    ): void {
        $this->info('Syncing raid instances + encounters...');

        $index = $client->getJournalInstanceIndex();
        if ($index === null) {
            $this->warn('Journal-instance index returned null (404). Skipping raids.');

            return;
        }

        $ids = $instanceMapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' raid instance IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $instUpserted = 0;
        $instSkipped = 0;
        $encUpserted = 0;
        $encSkipped = 0;

        foreach ($ids as $instanceId) {
            try {
                $detail = $client->getJournalInstance($instanceId);
                $media = $client->getJournalInstanceMedia($instanceId);
            } catch (Throwable $e) {
                Log::warning("Raid instance sync skipped id={$instanceId}: ".$e->getMessage());
                $instSkipped++;
                $bar->advance();

                continue;
            }

            $mediaUrl = $instanceMapper->extractMediaUrl($media);
            $instanceDto = $instanceMapper->mapDetail($detail, $mediaUrl);
            if ($instanceDto === null) {
                $instSkipped++;
                $bar->advance();

                continue;
            }

            DB::transaction(function () use (
                $client,
                $encounterMapper,
                $instanceDto,
                &$instUpserted,
                &$encUpserted,
                &$encSkipped,
            ) {
                \App\Models\GameDataRaidInstance::updateOrCreate(
                    ['id' => $instanceDto->id],
                    [
                        'name' => $instanceDto->name,
                        'expansion_id' => $instanceDto->expansionId,
                        'display_order' => $instanceDto->displayOrder,
                        'media_url' => $instanceDto->mediaUrl,
                    ],
                );
                $instUpserted++;

                foreach ($instanceDto->encounterIds as $i => $encounterId) {
                    try {
                        $encDetail = $client->getJournalEncounter($encounterId);
                    } catch (Throwable $e) {
                        Log::warning("Encounter sync skipped id={$encounterId}: ".$e->getMessage());
                        $encSkipped++;

                        continue;
                    }

                    $creatureDisplayId = isset($encDetail['creature_display']['id'])
                        ? (int) $encDetail['creature_display']['id']
                        : (isset($encDetail['creature_displays'][0]['id'])
                            ? (int) $encDetail['creature_displays'][0]['id']
                            : null);

                    $portraitUrl = null;
                    if ($creatureDisplayId !== null) {
                        try {
                            $cdMedia = $client->getCreatureDisplayMedia($creatureDisplayId);
                            $portraitUrl = $encounterMapper->extractMediaUrl($cdMedia);
                        } catch (Throwable $e) {
                            Log::warning("Creature-display media skipped id={$creatureDisplayId}: ".$e->getMessage());
                        }
                    }

                    $encDto = $encounterMapper->mapDetail(
                        detail: $encDetail,
                        portraitUrl: $portraitUrl,
                        fallbackInstanceId: $instanceDto->id,
                        fallbackOrder: $i,
                    );
                    if ($encDto === null) {
                        $encSkipped++;

                        continue;
                    }

                    \App\Models\GameDataRaidEncounter::updateOrCreate(
                        ['id' => $encDto->id],
                        [
                            'raid_instance_id' => $encDto->raidInstanceId,
                            'name' => $encDto->name,
                            'display_order' => $encDto->displayOrder,
                            'creature_display_id' => $encDto->creatureDisplayId,
                            'portrait_url' => $encDto->portraitUrl,
                        ],
                    );
                    $encUpserted++;
                }
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Raid instances synced: {$instUpserted} upserted, {$instSkipped} skipped.");
        $this->info("Raid encounters synced: {$encUpserted} upserted, {$encSkipped} skipped.");
    }

    private function syncMythicKeystoneDungeons(
        BlizzardGameDataClient $client,
        \App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper $mapper,
    ): void {
        $this->info('Syncing mythic-keystone dungeons (current season)...');

        $seasonId = $client->getCurrentMythicPlusSeason();
        $season = $client->getMythicKeystoneSeason($seasonId);
        if ($season === null) {
            $this->warn("Season {$seasonId} payload returned null (404). Falling back to dungeon-index sync.");
            $dungeonIds = [];
        } else {
            $dungeonIds = [];
            foreach ($season['dungeons'] ?? [] as $entry) {
                if (isset($entry['id'])) {
                    $dungeonIds[] = (int) $entry['id'];
                }
            }
        }

        if ($dungeonIds === []) {
            // Fall back: index-driven sync (older expansions where season
            // payload is sparse).
            $index = $client->getMythicKeystoneDungeonIndex();
            if ($index === null) {
                $this->warn('Dungeon index also null. Skipping dungeons.');

                return;
            }
            $dungeonIds = $mapper->extractIndexIds($index);
        }

        $this->info('Will sync '.count($dungeonIds).' dungeons.');

        $bar = $this->output->createProgressBar(count($dungeonIds));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $dungeonIds, &$upserted, &$skipped, $bar) {
            foreach ($dungeonIds as $id) {
                try {
                    $detail = $client->getMythicKeystoneDungeon($id);
                } catch (Throwable $e) {
                    Log::warning("Dungeon sync skipped id={$id}: ".$e->getMessage());
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                // Mythic-keystone dungeon details do not currently expose a
                // `media` block, but the Blizzard API may add one — the mapper
                // already supports it via extractMediaUrl(). Pass null today.
                $dto = $mapper->mapDetail($detail, mediaUrl: null, journalInstanceId: null);
                if ($dto === null) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                \App\Models\GameDataMythicKeystoneDungeon::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'media_url' => $dto->mediaUrl,
                        'journal_instance_id' => $dto->journalInstanceId,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Mythic-keystone dungeons synced: {$upserted} upserted, {$skipped} skipped.");
    }

    private function syncKeystoneAffixes(
        BlizzardGameDataClient $client,
        \App\Blizzard\Mappers\GameDataKeystoneAffixMapper $mapper,
    ): void {
        $this->info('Syncing keystone affixes...');

        $index = $client->getKeystoneAffixIndex();
        if ($index === null) {
            $this->warn('Keystone-affix index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' affix IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getKeystoneAffix($id);
                    $media = $client->getKeystoneAffixMedia($id);
                } catch (Throwable $e) {
                    Log::warning("Affix sync skipped id={$id}: ".$e->getMessage());
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $iconUrl = $mapper->extractIconUrl($media);
                $dto = $mapper->mapDetail($detail, $iconUrl);
                if ($dto === null) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                \App\Models\GameDataKeystoneAffix::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'icon_url' => $dto->iconUrl,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Keystone affixes synced: {$upserted} upserted, {$skipped} skipped.");
    }
```

- [ ] **Step 5: Verify the command registers and shows the new resource**

Run:
```bash
php artisan list | grep blizzard:sync-game-data
php artisan blizzard:sync-game-data --help | head -20
```

Expected: command appears with the updated description listing `pve`.

- [ ] **Step 6: Commit**

Run:
```bash
git add app/Console/Commands/SyncGameData.php
git commit -m "feat(pve): extend blizzard:sync-game-data with pve arm (raids/dungeons/affixes)"
```

---

## Task 24: Artisan command tests — `pve` arm

**Files:**
- Modify: `tests/Feature/Console/SyncGameDataTest.php`

- [ ] **Step 1: Append the new tests**

Open `backend/tests/Feature/Console/SyncGameDataTest.php`. Add the new model imports near the top (next to the existing ones):

```php
use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataRaidInstance;
```

Append the following tests inside the test class (after the last existing test):

```php
    public function test_sync_pve_upserts_raid_instance_with_encounters_and_media(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);

        $mock->method('getJournalInstanceIndex')->willReturn([
            'instances' => [['id' => 1296, 'name' => 'Liberation of Undermine']],
        ]);

        $mock->method('getJournalInstance')->willReturn([
            'id' => 1296,
            'name' => 'Liberation of Undermine',
            'expansion' => ['id' => 1],
            'order_index' => 5,
            'encounters' => [
                ['id' => 2902, 'name' => 'Vexie'],
                ['id' => 2917, 'name' => 'Cauldron of Carnage'],
            ],
        ]);

        $mock->method('getJournalInstanceMedia')->willReturn([
            'assets' => [['key' => 'tile', 'value' => 'https://example/lou.jpg']],
        ]);

        $mock->method('getJournalEncounter')->willReturnCallback(function (int $id): array {
            return match ($id) {
                2902 => ['id' => 2902, 'name' => 'Vexie', 'creature_display' => ['id' => 109501], 'instance' => ['id' => 1296], 'order_index' => 0],
                2917 => ['id' => 2917, 'name' => 'Cauldron of Carnage', 'creature_display' => ['id' => 109502], 'instance' => ['id' => 1296], 'order_index' => 1],
            };
        });

        $mock->method('getCreatureDisplayMedia')->willReturnCallback(function (int $id): array {
            return ['assets' => [['key' => 'zoom', 'value' => "https://example/cd-{$id}.jpg"]]];
        });

        // Mythic-keystone branch — minimal, returns no dungeons (covered separately below).
        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn(['id' => 14, 'dungeons' => []]);
        $mock->method('getMythicKeystoneDungeonIndex')->willReturn(['dungeons' => []]);

        // Affix branch — minimal, no affixes.
        $mock->method('getKeystoneAffixIndex')->willReturn(['affixes' => []]);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $instance = GameDataRaidInstance::find(1296);
        $this->assertNotNull($instance);
        $this->assertSame('Liberation of Undermine', $instance->name);
        $this->assertSame(1, $instance->expansion_id);
        $this->assertSame(5, $instance->display_order);
        $this->assertSame('https://example/lou.jpg', $instance->media_url);

        $this->assertSame(2, GameDataRaidEncounter::where('raid_instance_id', 1296)->count());

        $vexie = GameDataRaidEncounter::find(2902);
        $this->assertSame('Vexie', $vexie->name);
        $this->assertSame(0, $vexie->display_order);
        $this->assertSame(109501, $vexie->creature_display_id);
        $this->assertSame('https://example/cd-109501.jpg', $vexie->portrait_url);
    }

    public function test_sync_pve_upserts_mythic_keystone_dungeons_from_current_season(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);

        $mock->method('getJournalInstanceIndex')->willReturn(['instances' => []]);

        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn([
            'id' => 14,
            'dungeons' => [
                ['id' => 503, 'name' => 'Ara-Kara'],
                ['id' => 504, 'name' => 'City of Threads'],
            ],
        ]);
        $mock->method('getMythicKeystoneDungeon')->willReturnCallback(function (int $id): array {
            return match ($id) {
                503 => ['id' => 503, 'name' => 'Ara-Kara, City of Echoes'],
                504 => ['id' => 504, 'name' => 'City of Threads'],
            };
        });

        $mock->method('getKeystoneAffixIndex')->willReturn(['affixes' => []]);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataMythicKeystoneDungeon::count());
        $this->assertSame('Ara-Kara, City of Echoes', GameDataMythicKeystoneDungeon::find(503)->name);
        $this->assertSame('City of Threads', GameDataMythicKeystoneDungeon::find(504)->name);
    }

    public function test_sync_pve_upserts_keystone_affixes_with_icons(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getJournalInstanceIndex')->willReturn(['instances' => []]);
        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn(['id' => 14, 'dungeons' => []]);
        $mock->method('getMythicKeystoneDungeonIndex')->willReturn(['dungeons' => []]);

        $mock->method('getKeystoneAffixIndex')->willReturn([
            'affixes' => [
                ['id' => 9, 'name' => 'Tyrannical'],
                ['id' => 10, 'name' => 'Fortified'],
            ],
        ]);
        $mock->method('getKeystoneAffix')->willReturnCallback(function (int $id): array {
            return match ($id) {
                9 => ['id' => 9, 'name' => 'Tyrannical'],
                10 => ['id' => 10, 'name' => 'Fortified'],
            };
        });
        $mock->method('getKeystoneAffixMedia')->willReturnCallback(function (int $id): array {
            return ['assets' => [['key' => 'icon', 'value' => "https://example/affix-{$id}.jpg"]]];
        });

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataKeystoneAffix::count());
        $tyr = GameDataKeystoneAffix::find(9);
        $this->assertSame('Tyrannical', $tyr->name);
        $this->assertSame('https://example/affix-9.jpg', $tyr->icon_url);
    }

    public function test_sync_pve_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getJournalInstanceIndex')->willReturn([
            'instances' => [['id' => 1296, 'name' => 'LoU']],
        ]);
        $mock->method('getJournalInstance')->willReturn([
            'id' => 1296,
            'name' => 'Liberation of Undermine',
            'expansion' => ['id' => 1],
            'order_index' => 5,
            'encounters' => [['id' => 2902, 'name' => 'Vexie']],
        ]);
        $mock->method('getJournalInstanceMedia')->willReturn([
            'assets' => [['key' => 'tile', 'value' => 'https://example/lou.jpg']],
        ]);
        $mock->method('getJournalEncounter')->willReturn([
            'id' => 2902, 'name' => 'Vexie', 'instance' => ['id' => 1296], 'order_index' => 0,
        ]);
        $mock->method('getCreatureDisplayMedia')->willReturn(null);

        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn([
            'id' => 14,
            'dungeons' => [['id' => 503]],
        ]);
        $mock->method('getMythicKeystoneDungeon')->willReturn([
            'id' => 503, 'name' => 'Ara-Kara',
        ]);
        $mock->method('getKeystoneAffixIndex')->willReturn([
            'affixes' => [['id' => 9, 'name' => 'Tyrannical']],
        ]);
        $mock->method('getKeystoneAffix')->willReturn(['id' => 9, 'name' => 'Tyrannical']);
        $mock->method('getKeystoneAffixMedia')->willReturn(null);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve']);

        $this->assertSame(1, GameDataRaidInstance::count(), 'rerun should not duplicate raid rows');
        $this->assertSame(1, GameDataRaidEncounter::count(), 'rerun should not duplicate encounter rows');
        $this->assertSame(1, GameDataMythicKeystoneDungeon::count(), 'rerun should not duplicate dungeon rows');
        $this->assertSame(1, GameDataKeystoneAffix::count(), 'rerun should not duplicate affix rows');
    }

    public function test_sync_pve_continues_when_individual_id_throws(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getJournalInstanceIndex')->willReturn([
            'instances' => [
                ['id' => 1296],
                ['id' => 1273],
            ],
        ]);
        $mock->method('getJournalInstance')->willReturnCallback(function (int $id): array {
            if ($id === 1296) {
                throw new \RuntimeException('simulated transient failure');
            }

            return [
                'id' => $id,
                'name' => 'Other raid',
                'expansion' => ['id' => 1],
                'order_index' => 0,
                'encounters' => [],
            ];
        });
        $mock->method('getJournalInstanceMedia')->willReturn(null);

        $mock->method('getCurrentMythicPlusSeason')->willReturn(14);
        $mock->method('getMythicKeystoneSeason')->willReturn(['id' => 14, 'dungeons' => []]);
        $mock->method('getMythicKeystoneDungeonIndex')->willReturn(['dungeons' => []]);
        $mock->method('getKeystoneAffixIndex')->willReturn(['affixes' => []]);

        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'pve'])
            ->assertExitCode(0);

        $this->assertNull(GameDataRaidInstance::find(1296));
        $this->assertNotNull(GameDataRaidInstance::find(1273), 'second instance still upserted');
    }
```

- [ ] **Step 2: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Console/SyncGameDataTest.php
```

Expected: existing 3 tests pass + 5 new tests pass = 8 total.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Feature/Console/SyncGameDataTest.php
git commit -m "test(pve): cover blizzard:sync-game-data pve arm (raids/dungeons/affixes/idempotence)"
```

---

## Task 25: API Resource — `RaidInstanceResource`

**Files:**
- Create: `app/Http/Resources/RaidInstanceResource.php`

- [ ] **Step 1: Write the resource**

Create `backend/app/Http/Resources/RaidInstanceResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $name
 * @property ?int $expansion_id
 * @property int $display_order
 * @property ?string $media_url
 */
class RaidInstanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'display_order' => (int) $this->display_order,
            'media_url' => $this->media_url,
            'expansion' => $this->relationLoaded('expansion') && $this->expansion
                ? [
                    'id' => (int) $this->expansion->id,
                    'name' => $this->expansion->name,
                    'display_order' => (int) $this->expansion->display_order,
                ]
                : null,
            'encounters' => $this->whenLoaded('encounters', fn () => $this->encounters
                ->map(fn ($e) => [
                    'id' => (int) $e->id,
                    'name' => $e->name,
                    'display_order' => (int) $e->display_order,
                    'creature_display_id' => $e->creature_display_id !== null
                        ? (int) $e->creature_display_id
                        : null,
                    'portrait_url' => $e->portrait_url,
                ])
                ->values()
                ->all()),
        ];
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Resources/RaidInstanceResource.php
git commit -m "feat(pve): add RaidInstanceResource"
```

---

## Task 26: API Resource — `MythicKeystoneDungeonsResponse` payload

The dungeons endpoint emits a single payload combining dungeons and the season's affixes (per spec §2.6: "Affixes ride along on the dungeons response, so the FE doesn't need a third call"). We compose the payload directly inside the controller method (see Task 27); no dedicated `JsonResource` class is required for the wrapper. We do, however, want a resource for individual dungeons + affixes so the wrapping is consistent with the rest of the codebase.

**Files:**
- Create: `app/Http/Resources/MythicKeystoneDungeonResource.php`
- Create: `app/Http/Resources/KeystoneAffixResource.php`

- [ ] **Step 1: Write `MythicKeystoneDungeonResource`**

Create `backend/app/Http/Resources/MythicKeystoneDungeonResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $name
 * @property ?string $media_url
 * @property ?int $journal_instance_id
 */
class MythicKeystoneDungeonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'media_url' => $this->media_url,
            'journal_instance_id' => $this->journal_instance_id !== null
                ? (int) $this->journal_instance_id
                : null,
        ];
    }
}
```

- [ ] **Step 2: Write `KeystoneAffixResource`**

Create `backend/app/Http/Resources/KeystoneAffixResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $name
 * @property ?string $icon_url
 */
class KeystoneAffixResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'icon_url' => $this->icon_url,
        ];
    }
}
```

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Http/Resources/MythicKeystoneDungeonResource.php app/Http/Resources/KeystoneAffixResource.php
git commit -m "feat(pve): add MythicKeystoneDungeonResource + KeystoneAffixResource"
```

---

## Task 27: Controller — `GameDataController` with two PvE endpoints

**Files:**
- Create: `app/Http/Controllers/GameDataController.php`

- [ ] **Step 1: Write the controller**

Create `backend/app/Http/Controllers/GameDataController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\KeystoneAffixResource;
use App\Http\Resources\MythicKeystoneDungeonResource;
use App\Http\Resources\RaidInstanceResource;
use App\Models\GameDataExpansion;
use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataRaidInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameDataController extends Controller
{
    /**
     * GET /api/v1/game-data/raid-instances?expansion=current|all
     *
     * Public, long-cacheable. `expansion=current` (default) scopes to the
     * latest expansion (the row with the smallest `display_order` in
     * `game_data_expansions`). `expansion=all` returns every instance.
     *
     * Response shape:
     *   { data: [ { id, name, display_order, media_url, expansion: {...}, encounters: [...] }, ... ] }
     *
     * Cache header per spec §2.6: `Cache-Control: public, max-age=3600`.
     */
    public function raidInstances(Request $request): JsonResponse
    {
        $expansionFilter = $request->query('expansion', 'current');

        $query = GameDataRaidInstance::query()
            ->with(['expansion', 'encounters'])
            ->orderBy('display_order')
            ->orderBy('id');

        if ($expansionFilter === 'current') {
            $current = GameDataExpansion::query()
                ->orderBy('display_order')
                ->first();

            if ($current === null) {
                // No expansion data yet — return an empty payload rather than
                // an error so the FE can render an empty state cleanly.
                return response()->json(['data' => []])
                    ->header('Cache-Control', 'public, max-age=3600');
            }

            $query->where('expansion_id', $current->id);
        }
        // 'all' => no scope filter applied.

        $instances = $query->get();

        return response()->json([
            'data' => RaidInstanceResource::collection($instances),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /api/v1/game-data/mythic-keystone-dungeons?season=current
     *
     * Returns the dungeons in the current season plus the season's affixes
     * keyed by id. Season scoping today only supports `season=current` (per
     * spec §2.3 — older seasons are deferred to a future season-selector slice);
     * any other value is treated the same as `current`.
     *
     * Response shape:
     *   { data: { dungeons: [...], affixes: [{ id, name, icon_url }, ...] } }
     *
     * Cache header per spec §2.6: `Cache-Control: public, max-age=3600`.
     */
    public function mythicKeystoneDungeons(Request $request): JsonResponse
    {
        // Season is implicit: the sync command repopulates
        // game_data_mythic_keystone_dungeons each run with the current season.
        // We return whatever is in the table.
        $dungeons = GameDataMythicKeystoneDungeon::query()
            ->orderBy('name')
            ->get();

        $affixes = GameDataKeystoneAffix::query()
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'dungeons' => MythicKeystoneDungeonResource::collection($dungeons),
                'affixes' => KeystoneAffixResource::collection($affixes),
            ],
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Controllers/GameDataController.php
git commit -m "feat(pve): add GameDataController with raid-instances + mk-dungeons endpoints"
```

---

## Task 28: Routes — register the two PvE endpoints

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add the use-import**

Open `backend/routes/api.php`. Add the import alongside the other `App\Http\Controllers\...` imports near the top:

```php
use App\Http\Controllers\GameDataController;
```

- [ ] **Step 2: Add the route group**

Insert a new section after the Guild Routes block (before the Blizzard OAuth route):

```php
/*
|--------------------------------------------------------------------------
| Game Data Routes (public, long-cacheable)
|--------------------------------------------------------------------------
*/
Route::get('/game-data/raid-instances', [GameDataController::class, 'raidInstances'])
    ->name('game-data.raid-instances');
Route::get('/game-data/mythic-keystone-dungeons', [GameDataController::class, 'mythicKeystoneDungeons'])
    ->name('game-data.mythic-keystone-dungeons');
```

- [ ] **Step 3: Verify the routes register**

Run:
```bash
php artisan route:list | grep game-data
```

Expected: two new entries:

```
GET|HEAD  api/v1/game-data/raid-instances ............ game-data.raid-instances › GameDataController@raidInstances
GET|HEAD  api/v1/game-data/mythic-keystone-dungeons ... game-data.mythic-keystone-dungeons › GameDataController@mythicKeystoneDungeons
```

- [ ] **Step 4: Commit**

Run:
```bash
git add routes/api.php
git commit -m "feat(pve): register /game-data/raid-instances + /mythic-keystone-dungeons routes"
```

---

## Task 29: Endpoint test — `GameDataRaidInstancesEndpointTest`

**Files:**
- Create: `tests/Feature/Endpoints/GameDataRaidInstancesEndpointTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Feature/Endpoints/GameDataRaidInstancesEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataExpansion;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataRaidInstance;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataRaidInstancesEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    private function seedFixtures(): void
    {
        // Latest expansion has display_order=1 — see GameDataExpansionSeeder.
        // Seed two instances on the latest expansion + one on an older one.
        GameDataRaidInstance::create([
            'id' => 1296,
            'name' => 'Liberation of Undermine',
            'expansion_id' => 1,
            'display_order' => 5,
            'media_url' => 'https://example/lou.jpg',
        ]);
        GameDataRaidInstance::create([
            'id' => 1273,
            'name' => 'Nerub-ar Palace',
            'expansion_id' => 1,
            'display_order' => 1,
            'media_url' => 'https://example/nerub.jpg',
        ]);
        GameDataRaidInstance::create([
            'id' => 1207,
            'name' => 'Aberrus, the Shadowed Crucible',
            'expansion_id' => 2, // Dragonflight (older)
            'display_order' => 5,
            'media_url' => 'https://example/aberrus.jpg',
        ]);

        // Encounters under Liberation of Undermine.
        GameDataRaidEncounter::create([
            'id' => 2902,
            'raid_instance_id' => 1296,
            'name' => 'Vexie',
            'display_order' => 0,
            'creature_display_id' => 109501,
            'portrait_url' => 'https://example/cd-109501.jpg',
        ]);
        GameDataRaidEncounter::create([
            'id' => 2917,
            'raid_instance_id' => 1296,
            'name' => 'Cauldron of Carnage',
            'display_order' => 1,
            'creature_display_id' => 109502,
            'portrait_url' => 'https://example/cd-109502.jpg',
        ]);
    }

    public function test_default_returns_only_current_expansion_with_encounters_and_expansion_block(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertJsonCount(2, 'data'); // only the two TWW raids
        // Ordered by display_order asc → Nerub-ar Palace (1) before Liberation of Undermine (5).
        $response->assertJsonPath('data.0.id', 1273);
        $response->assertJsonPath('data.1.id', 1296);
        $response->assertJsonPath('data.1.name', 'Liberation of Undermine');
        $response->assertJsonPath('data.1.media_url', 'https://example/lou.jpg');
        $response->assertJsonPath('data.1.expansion.id', 1);
        $response->assertJsonPath('data.1.expansion.name', 'The War Within');
        $response->assertJsonCount(2, 'data.1.encounters');
        $response->assertJsonPath('data.1.encounters.0.id', 2902);
        $response->assertJsonPath('data.1.encounters.0.name', 'Vexie');
        $response->assertJsonPath('data.1.encounters.0.creature_display_id', 109501);
        $response->assertJsonPath('data.1.encounters.0.portrait_url', 'https://example/cd-109501.jpg');
    }

    public function test_expansion_current_explicit_matches_default(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances?expansion=current');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', 1273);
    }

    public function test_expansion_all_returns_every_expansion(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances?expansion=all');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains(1207, $ids); // older Aberrus instance present
        $this->assertContains(1273, $ids);
        $this->assertContains(1296, $ids);
    }

    public function test_response_carries_cache_control_header(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'public, max-age=3600');
    }

    public function test_returns_empty_data_when_no_instances_seeded(): void
    {
        // No seedFixtures call — table is empty.
        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    public function test_endpoint_is_public_no_auth(): void
    {
        $this->seedFixtures();

        // No auth headers — should still return 200.
        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
    }

    public function test_endpoint_returns_empty_when_no_expansions_seeded_at_all(): void
    {
        // Wipe expansions even though we seeded them in setUp.
        GameDataExpansion::query()->delete();

        $response = $this->getJson('/api/v1/game-data/raid-instances');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
        $response->assertHeader('Cache-Control', 'public, max-age=3600');
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/GameDataRaidInstancesEndpointTest.php
```

Expected: 7 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Feature/Endpoints/GameDataRaidInstancesEndpointTest.php
git commit -m "test(pve): cover /api/v1/game-data/raid-instances endpoint"
```

---

## Task 30: Endpoint test — `GameDataMythicKeystoneDungeonsEndpointTest`

**Files:**
- Create: `tests/Feature/Endpoints/GameDataMythicKeystoneDungeonsEndpointTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Feature/Endpoints/GameDataMythicKeystoneDungeonsEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMythicKeystoneDungeon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataMythicKeystoneDungeonsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixtures(): void
    {
        GameDataMythicKeystoneDungeon::create([
            'id' => 503,
            'name' => 'Ara-Kara, City of Echoes',
            'media_url' => 'https://example/arak.jpg',
            'journal_instance_id' => 1271,
        ]);
        GameDataMythicKeystoneDungeon::create([
            'id' => 504,
            'name' => 'City of Threads',
            'media_url' => null,
            'journal_instance_id' => null,
        ]);

        GameDataKeystoneAffix::create([
            'id' => 9,
            'name' => 'Tyrannical',
            'icon_url' => 'https://example/affix-9.jpg',
        ]);
        GameDataKeystoneAffix::create([
            'id' => 10,
            'name' => 'Fortified',
            'icon_url' => 'https://example/affix-10.jpg',
        ]);
    }

    public function test_returns_dungeons_and_affixes_in_one_payload(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.dungeons');
        $response->assertJsonCount(2, 'data.affixes');

        // Dungeons sorted by name ascending.
        $response->assertJsonPath('data.dungeons.0.id', 503);
        $response->assertJsonPath('data.dungeons.0.name', 'Ara-Kara, City of Echoes');
        $response->assertJsonPath('data.dungeons.0.media_url', 'https://example/arak.jpg');
        $response->assertJsonPath('data.dungeons.0.journal_instance_id', 1271);

        $response->assertJsonPath('data.dungeons.1.id', 504);
        $response->assertJsonPath('data.dungeons.1.media_url', null);
        $response->assertJsonPath('data.dungeons.1.journal_instance_id', null);

        // Affixes sorted by id ascending.
        $response->assertJsonPath('data.affixes.0.id', 9);
        $response->assertJsonPath('data.affixes.0.name', 'Tyrannical');
        $response->assertJsonPath('data.affixes.0.icon_url', 'https://example/affix-9.jpg');
    }

    public function test_default_no_query_string_works(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.dungeons');
    }

    public function test_response_carries_cache_control_header(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertHeader('Cache-Control', 'public, max-age=3600');
    }

    public function test_returns_empty_arrays_when_tables_empty(): void
    {
        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'dungeons' => [],
                'affixes' => [],
            ],
        ]);
    }

    public function test_endpoint_is_public_no_auth(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/GameDataMythicKeystoneDungeonsEndpointTest.php
```

Expected: 5 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Feature/Endpoints/GameDataMythicKeystoneDungeonsEndpointTest.php
git commit -m "test(pve): cover /api/v1/game-data/mythic-keystone-dungeons endpoint"
```

---

## Task 31: Run the sync locally to populate dev data

**Files:** none (operational only)

- [ ] **Step 1: Run the seeder + migrate (idempotent)**

Run:
```bash
php artisan migrate
php artisan db:seed --class=GameDataExpansionSeeder
```

Expected: migrations are at head; expansion rows present.

- [ ] **Step 2: Run the new pve arm**

Run:
```bash
php artisan blizzard:sync-game-data pve
```

Expected: three progress bars run sequentially (raids → dungeons → affixes). May take ~3-5 minutes against Blizzard's rate limit (each raid has ~8 encounters → ~2 detail calls + 1 media call each).

- [ ] **Step 3: Verify rows landed**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataRaidInstance;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataKeystoneAffix;
dump('raid_instances total: '.GameDataRaidInstance::count());
dump('raid_encounters total: '.GameDataRaidEncounter::count());
dump('mk_dungeons total: '.GameDataMythicKeystoneDungeon::count());
dump('keystone_affixes total: '.GameDataKeystoneAffix::count());
dump('TWW raids:');
dump(GameDataRaidInstance::where('expansion_id', 1)->pluck('name')->toArray());
"
```

Expected: counts >0 across all four tables. TWW raid names include the current-patch raids (e.g., `Liberation of Undermine`, `Nerub-ar Palace`).

- [ ] **Step 4: Smoke-test the endpoints**

Run:
```bash
curl -s http://localhost:8000/api/v1/game-data/raid-instances | python3 -m json.tool | head -50
curl -s http://localhost:8000/api/v1/game-data/mythic-keystone-dungeons?season=current | python3 -m json.tool | head -30
curl -sI http://localhost:8000/api/v1/game-data/raid-instances | grep -i cache-control
```

Expected: first two emit JSON with the documented shape; third shows `Cache-Control: public, max-age=3600`.

- [ ] **Step 5: No commit (operational state, not code)**

---

## Task 32: Update `backend/CLAUDE.md` with the new slice notes

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Add the slice bullet**

Open `backend/CLAUDE.md`. Find the existing per-slice bullet list under "## Architecture > ### Blizzard Module" (the section that already lists the Plan 5 game-data resolvers — factions, titles, mounts, achievements). Append a new bullet at the end of that list:

```markdown
- **Game-data PvE resolver (Plan A — PvE tab redesign).** Four new tables: `game_data_raid_instances` (PK = Blizzard journal-instance id, FK to `game_data_expansions`), `game_data_raid_encounters` (PK = journal-encounter id, FK to raid_instances with cascade delete; carries `creature_display_id` + `portrait_url`), `game_data_mythic_keystone_dungeons` (PK = mythic-keystone dungeon id; `journal_instance_id` is a soft join key, **not** FK-constrained, since older-expansion dungeons may reference an instance we did not sync), `game_data_keystone_affixes` (PK = affix id; `icon_url`). Populated by `php artisan blizzard:sync-game-data pve` — extends the same command that already powers the Plan-5 resolvers, scheduled weekly with the rest. Sync sequence: (1) `/data/wow/journal-instance/index` + per-id detail/media → upsert raid instances + their encounter rosters (encounter detail + creature-display media give the boss portrait), (2) `/data/wow/mythic-keystone/season/index` resolves the current season id, then `/data/wow/mythic-keystone/season/{id}.dungeons` drives a per-id `/mythic-keystone/dungeon/{id}` fan-out (dungeon endpoints live in **`dynamic-{region}`** namespace, unlike the journal-instance family which is `static-{region}`), (3) `/data/wow/keystone-affix/index` + per-id detail + media. Two new public endpoints (no auth) on a new `GameDataController`: `GET /api/v1/game-data/raid-instances?expansion=current|all` (default `current` scopes to the latest `game_data_expansions.display_order=1`; `all` returns every expansion) and `GET /api/v1/game-data/mythic-keystone-dungeons?season=current` (returns dungeons + the season's affixes piggybacked into the same payload — keyed by id, ~12-16 affix rows). Both endpoints emit `Cache-Control: public, max-age=3600` per spec §2.6 — FE caches via TanStack Query with `staleTime: Infinity`. No feature flag — endpoints serve whatever is in the tables, and an empty table yields `data: []` rather than an error.
```

- [ ] **Step 2: Commit**

Run:
```bash
git add CLAUDE.md
git commit -m "docs(pve): document PvE game-data resolver slice in CLAUDE.md"
```

---

## Task 33: Final verification — full BE suite + Pint

**Files:** none (test runs only)

- [ ] **Step 1: Full BE test suite**

Run:
```bash
composer test
```

Expected: all tests pass. New tests added by this plan: ~40 tests across 4 mapper files + 1 client file + 1 command file + 2 endpoint files. Existing tests (Plan 4 + Plan 5 + others) all green.

- [ ] **Step 2: Pint formatting**

Run:
```bash
./vendor/bin/pint --test
```

Expected: clean. If errors, run `./vendor/bin/pint` and re-stage any auto-fixed files:

```bash
./vendor/bin/pint
git status --short
git add <files>
git commit -m "style(pve): pint auto-format"
```

- [ ] **Step 3: Confirm migrations are at head and reversible**

Run:
```bash
php artisan migrate:status | tail -10
```

Expected: the four new PvE migrations show `Ran` status.

Run:
```bash
php artisan migrate:rollback --step=4
php artisan migrate
```

Expected: all four roll back cleanly (drop in reverse-creation order — encounters before instances because of the FK), then re-apply cleanly.

---

## Task 34: Final review pass

**Files:** none (review only)

- [ ] **Step 1: Confirm all commits land on the feature branch**

Run:
```bash
git log master..HEAD --oneline
```

Expected: roughly 20-22 commits ranging from "feat(pve): add game_data_raid_instances table" through "docs(pve): document PvE game-data resolver slice in CLAUDE.md".

- [ ] **Step 2: Re-run the full suite one more time**

Run:
```bash
composer test
```

Expected: all green.

- [ ] **Step 3: Push the branch**

Run:
```bash
git push -u origin feature/pve-tab-redesign
```

- [ ] **Step 4: Open the PR**

Title: `Plan A — PvE game-data resolver (BE)`. Body should reference:
- Spec: `backend/docs/superpowers/specs/2026-05-01-pve-tab-redesign-design.md`
- Plan: `backend/docs/superpowers/plans/2026-05-01-pve-game-data-resolver-slice.md`
- Note that Plan B (FE rebuild) starts only after this PR merges, per spec §3.

The branch will receive Plan B commits next; final merge to master happens once both plans land — operator's call whether to merge per-plan or hold open until both finish (mirror the Plan 5 single-feature-branch model).

---

## Spec coverage

Mapping between spec requirements and tasks in this plan. Confirms every BE-relevant requirement in §2.5, §2.6, §3, §4 is covered by at least one task.

### §2.5 — New BE game-data tables
- `game_data_raid_instances` schema (id PK, name, expansion_id FK, display_order, media_url) — **Task 2** (migration), **Task 6** (model with `expansion()`/`encounters()` relations), **Task 9** (smoke + commit).
- `game_data_raid_encounters` schema (id PK, raid_instance_id FK, name, display_order, creature_display_id, portrait_url) — **Task 3** (migration), **Task 7** (model with `raidInstance()` relation), **Task 9** (smoke + commit).
- `game_data_mythic_keystone_dungeons` schema (id PK, name, media_url, journal_instance_id soft join) — **Task 4** (migration), **Task 8** (model), **Task 9** (commit).
- `game_data_keystone_affixes` schema (id PK, name, icon_url) — **Task 5** (migration), **Task 8** (model), **Task 9** (commit).
- Sync command `php artisan blizzard:sync-game-data pve` covering all 4 steps in §2.5 — **Task 23** (extend `SyncGameData` with `pve` arm + 3 sub-syncs: raids/dungeons/affixes), **Task 24** (idempotence + per-id failure tolerance test).
- Idempotent + re-runnable — covered by `updateOrCreate` in **Task 23**, asserted by **Task 24** `test_sync_pve_is_idempotent`.
- Scheduled weekly — **already in place** in `bootstrap/app.php` (`$schedule->command('blizzard:sync-game-data')->weeklyOn(0, '03:00')`); the no-arg invocation now expands to `[factions, titles, mounts, achievements, pve]` per **Task 23 Step 3**.

### §2.6 — API delivery
- `GET /api/game-data/raid-instances?expansion=current` (default current; `all` toggles to every expansion; embedded encounter roster + media URLs) — **Task 25** (resource), **Task 27** (controller), **Task 28** (route).
- `GET /api/game-data/mythic-keystone-dungeons?season=current` (dungeons + affixes piggybacked) — **Task 26** (resources), **Task 27** (controller), **Task 28** (route).
- Both endpoints public (no auth) — **Task 28** routes have no `auth:sanctum` middleware; **Tasks 29 & 30** assert with `test_endpoint_is_public_no_auth`.
- `Cache-Control: public, max-age=3600` — **Task 27** (controller emits header on both endpoints), **Task 29** + **Task 30** assert via `assertHeader`.
- Feature tests for both endpoints (happy path + `expansion=all` + `expansion=current` + season=current) — **Task 29** (raid-instances: 7 tests including `expansion=all`, `expansion=current` explicit, default, empty-table, no-auth, cache header, no-expansions-seeded), **Task 30** (mk-dungeons: 5 tests including `season=current`, default, cache header, empty-arrays, no-auth).

### §3 — Slicing
- Plan A is BE-only, no FE files — confirmed: every task in this plan touches `backend/` only; the FE consumer rebuild is Plan B.
- Branch `feature/pve-tab-redesign` cut from `master` — **Task 1**.
- Independently shippable; endpoints return real data after `sync-game-data pve` runs — **Task 31** verifies via `curl`.

### §4 — Acceptance
- BE: `php artisan blizzard:sync-game-data pve` succeeds end-to-end on a fresh DB; re-running is a no-op — **Task 24** `test_sync_pve_is_idempotent`, **Task 31** runs it locally.
- BE: `GET /api/game-data/raid-instances?expansion=current` returns populated, schema-correct JSON — **Task 29**.
- BE: `GET /api/game-data/mythic-keystone-dungeons?season=current` returns populated, schema-correct JSON — **Task 30**.
- BE: `php artisan test` is green for the new sync command + endpoints — **Task 33**.
- (FE acceptance items — visiting `/characters/.../pve`, "Show legacy raids", "All Runs" tab, boss portraits loading — are Plan B's responsibility and intentionally NOT covered by this plan.)

### Endpoints + media coverage cross-check (spec §2.5 explicit list)
- `GET /data/wow/journal-instance/index` — **Task 18** (`getJournalInstanceIndex`).
- `GET /data/wow/journal-instance/{id}` — **Task 18** (`getJournalInstance`).
- `GET /data/wow/journal-encounter/{id}` — **Task 18** (`getJournalEncounter`).
- `GET /data/wow/mythic-keystone/dungeon/index` — **Task 20** (`getMythicKeystoneDungeonIndex`).
- `GET /data/wow/mythic-keystone/dungeon/{id}` — **Task 20** (`getMythicKeystoneDungeon`).
- `GET /data/wow/keystone-affix/index` — **Task 21** (`getKeystoneAffixIndex`).
- `GET /data/wow/keystone-affix/{id}` — **Task 21** (`getKeystoneAffix`).
- `GET /data/wow/mythic-keystone/season/index` — **already present** as `getCurrentMythicPlusSeason()` in `BlizzardGameDataClient`; consumed in **Task 23** to resolve the season id.
- `GET /data/wow/mythic-keystone/season/{id}` — **Task 20** (`getMythicKeystoneSeason`).
- Media endpoints: `media/journal-instance/{id}` (**Task 18**, `getJournalInstanceMedia`), `media/creature-display/{id}` (**Task 20**, `getCreatureDisplayMedia`), `media/keystone-affix/{id}` (**Task 21**, `getKeystoneAffixMedia`).

All 4 deliverable buckets from the parent prompt — migrations, models, client methods, sync command, controller endpoints, resources, feature tests, unit tests, CLAUDE.md update — have at least one task each.
