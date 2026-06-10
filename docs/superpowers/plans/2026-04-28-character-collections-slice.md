# Plan 4 — Character Collections Slice (Mounts / Pets / Toys)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md` (slice 4)

**Goal:** End-to-end ship the character collections slice — surface the account-wide mounts, battle pets, and toys for a character through a single new sync slice, three sub-tables, three resources, and three FE subtabs that today render `EmptyTab`.

**Architecture:** One sync slice, three sub-tables. `SyncCharacterData::syncCollections()` calls a single new `BlizzardProfileClient::getCharacterCollections()` method that fans out three Blizzard endpoints in parallel via `Http::pool()` (well under the 3-per-chunk PvP cap, so no chunking needed). All three sub-table writes happen in **one** `DB::transaction` so a partial failure rolls back consistently. A single `collections_synced_at` column on `characters` and a single `BLIZZARD_SYNC_COLLECTIONS_ENABLED` flag govern the entire slice. The FE adds three nullable arrays to `CharacterResource` and renders simple grids with counts in each subtab header.

**Persistence-shape DECISION (resolves spec §3 row 4):** **Three separate tables** — `character_mounts`, `character_pets`, `character_toys`. Rationale: (1) pets carry distinct columns (`level`, `breed_id`, `quality`, `is_favorite`, `creature_display_id`) that mounts and toys do not; a polymorphic table would force nullables that hurt readability and prevent index narrowness, (2) the FE has three distinct subtabs that each query their own collection — three Eloquent relations map 1:1 to the three FE consumers, (3) Plan 2's precedent: `character_pvp_brackets`, `character_professions`, and `raid_encounter_kills` are each their own table even though they sync as a group, (4) future per-collection enrichment (mount source lookup, pet rarity computation, toy categories) is cleaner with focused schemas. The mild repetition of three near-identical migrations / models / mappers is the cost of paid-once clarity.

**Wowhead-link DECISION (resolves directive ambiguity):** **Toys link via `item={toy_id}`** (toys are items in WoW's data model — Wowhead's Power widget supports it natively). **Pets link via `npc={creature_display_id}`** when the field is present in Blizzard's response (pet species are creatures in Wowhead's data model). **Mounts ship without Wowhead tooltips in v1** — the collected-list endpoint returns the journal `mount.id` (e.g., 6) which is neither an item nor a spell; resolving the summon-spell id requires per-mount `/data/wow/mount/{id}` lookups (rate-limit-hostile for hundreds of mounts). v1 renders mount name + sortable list. A follow-up slice can add mount enrichment if the parity gap with masked-armory turns out to matter; called out explicitly in §"Out of this plan".

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL JSONB, Laravel Horizon, Redis. Vue 3 `<script setup>` + TS, Tailwind/DaisyUI. Tests are e2e against the real Blizzard API gated on credentials.

**Out of this plan (deferred):**
- Heirlooms (spec §4 — separate Blizzard endpoint).
- Mount summon-spell enrichment (per above).
- Pet rarity / breed metadata enrichment beyond what the collected-list endpoint already returns.
- Search / filter / sort UI in the subtabs (callable, but explicitly out of this plan; render-and-list is enough for v1).
- Wowhead tooltip refresh inside the subtab (the existing `useWowheadRefresh` is invoked by the parent `CharacterDetailLayout` already; rebinding is on render which suffices).
- Feature-flag ramp-up beyond default `false`. Operator flips `BLIZZARD_SYNC_COLLECTIONS_ENABLED=true` after merge.

**Sequencing inside Plan 4:** This is **slice 4 of 5** by spec sequencing (after stats / titles / reputations, before achievements). Single PR ships all three sub-tabs. The plan is internally splittable along sub-table boundaries (Mounts / Pets / Toys) if a sub-PR strategy is preferred — each migration, DTO, mapper, model, and FE subtab is independent of its siblings; only `BlizzardProfileClient::getCharacterCollections()`, `SyncCharacterData::syncCollections()`, and `CharacterResource` couple them.

**Deploy-ready at the end of:** this plan, with `BLIZZARD_SYNC_COLLECTIONS_ENABLED=false` in production until operators flip it. Schema additions are strictly additive (new tables, one nullable column, no index swaps).

---

## Task 1: Create / continue the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify working tree is clean and check current branch**

Run:
```bash
git status --short
git branch --show-current
```

Expected: working tree is clean. If you are not already on `feature/character-collections-and-stats` and no other Plan 4 slice has yet been started, create the branch off `master`:

```bash
git checkout master
git pull --ff-only
git checkout -b feature/character-collections-and-stats
```

If the branch already exists from a sibling slice (e.g., stats, titles, reputations) and is checked out, leave it alone — this plan adds tasks to that branch.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: a stable, mergeable starting point. If a sibling Plan 4 slice has already merged, its commits should be visible.

---

## Task 2: Migration — add `collections_synced_at` to `characters`

**Files:**
- Create: `database/migrations/2026_04_28_100001_add_collections_synced_at_to_characters.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('collections_synced_at')->nullable()->after('raids_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('collections_synced_at');
        });
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasColumn('characters', 'collections_synced_at'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100001_add_collections_synced_at_to_characters.php
git commit -m "Add collections_synced_at to characters"
```

---

## Task 3: Migration — create `character_mounts`

**Files:**
- Create: `database/migrations/2026_04_28_100002_create_character_mounts_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_mounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('mount_id');
            $table->string('name', 200);
            $table->boolean('is_useable')->default(true);
            $table->timestamps();

            $table->unique(['character_id', 'mount_id'], 'character_mounts_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_mounts');
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_mounts'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100002_create_character_mounts_table.php
git commit -m "Add character_mounts table"
```

---

## Task 4: Migration — create `character_pets`

**Files:**
- Create: `database/migrations/2026_04_28_100003_create_character_pets_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('pet_id'); // Blizzard pet id (unique per-account collected pet, NOT species id)
            $table->integer('species_id'); // species/creature catalog id (used for FE icon lookup)
            $table->string('name', 200);
            $table->smallInteger('level')->default(1);
            $table->smallInteger('breed_id')->nullable();
            $table->string('quality', 20)->nullable(); // poor / common / uncommon / rare / epic / legendary
            $table->boolean('is_favorite')->default(false);
            $table->integer('creature_display_id')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'pet_id'], 'character_pets_unique');
            $table->index('character_id');
            $table->index(['character_id', 'species_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_pets');
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_pets'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100003_create_character_pets_table.php
git commit -m "Add character_pets table"
```

---

## Task 5: Migration — create `character_toys`

**Files:**
- Create: `database/migrations/2026_04_28_100004_create_character_toys_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_toys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('toy_id'); // toy item id — Wowhead-linkable as item={toy_id}
            $table->string('name', 200);
            $table->timestamps();

            $table->unique(['character_id', 'toy_id'], 'character_toys_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_toys');
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_toys'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100004_create_character_toys_table.php
git commit -m "Add character_toys table"
```

---

## Task 6: `CharacterMount` model

**Files:**
- Create: `app/Models/CharacterMount.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterMount extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'mount_id',
        'name',
        'is_useable',
    ];

    protected function casts(): array
    {
        return [
            'mount_id' => 'integer',
            'is_useable' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Models/CharacterMount.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/CharacterMount.php
git commit -m "Add CharacterMount model"
```

---

## Task 7: `CharacterPet` model

**Files:**
- Create: `app/Models/CharacterPet.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterPet extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'pet_id',
        'species_id',
        'name',
        'level',
        'breed_id',
        'quality',
        'is_favorite',
        'creature_display_id',
    ];

    protected function casts(): array
    {
        return [
            'pet_id' => 'integer',
            'species_id' => 'integer',
            'level' => 'integer',
            'breed_id' => 'integer',
            'is_favorite' => 'boolean',
            'creature_display_id' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Models/CharacterPet.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/CharacterPet.php
git commit -m "Add CharacterPet model"
```

---

## Task 8: `CharacterToy` model

**Files:**
- Create: `app/Models/CharacterToy.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterToy extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'toy_id',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'toy_id' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Models/CharacterToy.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/CharacterToy.php
git commit -m "Add CharacterToy model"
```

---

## Task 9: Extend `Character` model — fillable, casts, relations, staleness

**Files:**
- Modify: `app/Models/Character.php`

- [ ] **Step 1: Add `collections_synced_at` to `$fillable`**

In `app/Models/Character.php`, locate the `$fillable` array. After the `'raids_synced_at',` line, add:

```php
        'collections_synced_at',
```

- [ ] **Step 2: Add `collections_synced_at` cast**

In the `casts()` method, after the line `'raids_synced_at' => 'datetime',`, add:

```php
            'collections_synced_at' => 'datetime',
```

- [ ] **Step 3: Add the three relations**

After the `raidEncounterKills()` relation method, add:

```php
    public function mounts(): HasMany
    {
        return $this->hasMany(CharacterMount::class);
    }

    public function pets(): HasMany
    {
        return $this->hasMany(CharacterPet::class);
    }

    public function toys(): HasMany
    {
        return $this->hasMany(CharacterToy::class);
    }
```

- [ ] **Step 4: Add `isCollectionsStale()`**

After `isRaidsStale()`, add:

```php
    public function isCollectionsStale(): bool
    {
        return ! $this->collections_synced_at
            || $this->collections_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.collections');
    }
```

- [ ] **Step 5: Verify no syntax errors**

Run:
```bash
php -l app/Models/Character.php
php artisan tinker --execute "echo App\Models\Character::factory()->make()->isCollectionsStale() ? 'stale' : 'fresh';"
```

Expected: `No syntax errors detected`. Tinker prints `stale` (the new factory instance has no `collections_synced_at`).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Character.php
git commit -m "Add collections relations + isCollectionsStale to Character model"
```

---

## Task 10: `CharacterMount` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterMount.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterMount
{
    public function __construct(
        public int $mountId,
        public string $name,
        public bool $isUseable,
    ) {}
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/DTO/CharacterMount.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterMount.php
git commit -m "Add CharacterMount DTO"
```

---

## Task 11: `CharacterPet` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterPet.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterPet
{
    public function __construct(
        public int $petId,
        public int $speciesId,
        public string $name,
        public int $level,
        public ?int $breedId,
        public ?string $quality,
        public bool $isFavorite,
        public ?int $creatureDisplayId,
    ) {}
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/DTO/CharacterPet.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterPet.php
git commit -m "Add CharacterPet DTO"
```

---

## Task 12: `CharacterToy` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterToy.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterToy
{
    public function __construct(
        public int $toyId,
        public string $name,
    ) {}
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/DTO/CharacterToy.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterToy.php
git commit -m "Add CharacterToy DTO"
```

---

## Task 13: `CharacterMountMapper` — TDD

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/CharacterMountMapperTest.php`
- Create: `app/Blizzard/Mappers/CharacterMountMapper.php`

The Blizzard `/collections/mounts` endpoint shape is:
```json
{
  "mounts": [
    { "mount": { "id": 6, "name": "Brown Horse", "key": { "href": "..." } }, "is_useable": true },
    ...
  ]
}
```

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Blizzard/Mappers/CharacterMountMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterMountMapper;
use Tests\TestCase;

class CharacterMountMapperTest extends TestCase
{
    public function test_maps_mount_entries(): void
    {
        $payload = [
            'mounts' => [
                ['mount' => ['id' => 6,  'name' => 'Brown Horse'],   'is_useable' => true],
                ['mount' => ['id' => 64, 'name' => 'Red Wolf'],       'is_useable' => false],
            ],
        ];

        $dtos = (new CharacterMountMapper)->map($payload);

        $this->assertCount(2, $dtos);
        $this->assertSame(6, $dtos[0]->mountId);
        $this->assertSame('Brown Horse', $dtos[0]->name);
        $this->assertTrue($dtos[0]->isUseable);
        $this->assertSame(64, $dtos[1]->mountId);
        $this->assertFalse($dtos[1]->isUseable);
    }

    public function test_returns_empty_for_null_or_missing_mounts(): void
    {
        $this->assertSame([], (new CharacterMountMapper)->map(null));
        $this->assertSame([], (new CharacterMountMapper)->map([]));
        $this->assertSame([], (new CharacterMountMapper)->map(['mounts' => []]));
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $payload = ['mounts' => [
            ['mount' => ['id' => 0, 'name' => 'broken'], 'is_useable' => true],
            ['mount' => ['id' => 6, 'name' => 'Brown Horse'], 'is_useable' => true],
        ]];

        $dtos = (new CharacterMountMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(6, $dtos[0]->mountId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterMountMapperTest.php
```

Expected: FAIL with `Class "App\Blizzard\Mappers\CharacterMountMapper" not found` (or similar).

- [ ] **Step 3: Write the mapper**

Create `app/Blizzard/Mappers/CharacterMountMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterMount;

class CharacterMountMapper
{
    /**
     * @return CharacterMount[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['mounts'] ?? [] as $entry) {
            $id = (int) ($entry['mount']['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $out[] = new CharacterMount(
                mountId: $id,
                name: (string) ($entry['mount']['name'] ?? 'Unknown'),
                isUseable: (bool) ($entry['is_useable'] ?? false),
            );
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterMountMapperTest.php
```

Expected: PASS (3 tests, all green).

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Mappers/CharacterMountMapper.php tests/Unit/Blizzard/Mappers/CharacterMountMapperTest.php
git commit -m "Add CharacterMountMapper with unit tests"
```

---

## Task 14: `CharacterPetMapper` — TDD

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/CharacterPetMapperTest.php`
- Create: `app/Blizzard/Mappers/CharacterPetMapper.php`

The Blizzard `/collections/pets` endpoint shape is:
```json
{
  "pets": [
    {
      "id": 4242,
      "species": { "id": 1455, "name": "Lil' K.T.", "creature_display": { "id": 28168 } },
      "level": 25,
      "quality": { "type": "RARE", "name": "Rare" },
      "stats": { "breed_id": 9, ... },
      "is_favorite": true,
      "name": "Lil' K.T."
    }
  ]
}
```

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Blizzard/Mappers/CharacterPetMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterPetMapper;
use Tests\TestCase;

class CharacterPetMapperTest extends TestCase
{
    public function test_maps_pet_entries(): void
    {
        $payload = [
            'pets' => [
                [
                    'id' => 4242,
                    'species' => [
                        'id' => 1455,
                        'name' => "Lil' K.T.",
                        'creature_display' => ['id' => 28168],
                    ],
                    'level' => 25,
                    'quality' => ['type' => 'RARE', 'name' => 'Rare'],
                    'stats' => ['breed_id' => 9],
                    'is_favorite' => true,
                ],
            ],
        ];

        $dtos = (new CharacterPetMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(4242, $dtos[0]->petId);
        $this->assertSame(1455, $dtos[0]->speciesId);
        $this->assertSame("Lil' K.T.", $dtos[0]->name);
        $this->assertSame(25, $dtos[0]->level);
        $this->assertSame(9, $dtos[0]->breedId);
        $this->assertSame('rare', $dtos[0]->quality);
        $this->assertTrue($dtos[0]->isFavorite);
        $this->assertSame(28168, $dtos[0]->creatureDisplayId);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $payload = [
            'pets' => [
                [
                    'id' => 100,
                    'species' => ['id' => 200, 'name' => 'Unknown'],
                    'level' => 1,
                ],
            ],
        ];

        $dtos = (new CharacterPetMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertNull($dtos[0]->breedId);
        $this->assertNull($dtos[0]->quality);
        $this->assertFalse($dtos[0]->isFavorite);
        $this->assertNull($dtos[0]->creatureDisplayId);
    }

    public function test_returns_empty_for_null_or_missing_pets(): void
    {
        $this->assertSame([], (new CharacterPetMapper)->map(null));
        $this->assertSame([], (new CharacterPetMapper)->map([]));
        $this->assertSame([], (new CharacterPetMapper)->map(['pets' => []]));
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $payload = ['pets' => [
            ['id' => 0, 'species' => ['id' => 1, 'name' => 'x'], 'level' => 1],
            ['id' => 9, 'species' => ['id' => 2, 'name' => 'good'], 'level' => 25],
        ]];

        $dtos = (new CharacterPetMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(9, $dtos[0]->petId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterPetMapperTest.php
```

Expected: FAIL with `Class "App\Blizzard\Mappers\CharacterPetMapper" not found`.

- [ ] **Step 3: Write the mapper**

Create `app/Blizzard/Mappers/CharacterPetMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterPet;

class CharacterPetMapper
{
    /**
     * @return CharacterPet[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['pets'] ?? [] as $entry) {
            $petId = (int) ($entry['id'] ?? 0);
            if ($petId === 0) {
                continue;
            }

            $quality = isset($entry['quality']['type'])
                ? strtolower((string) $entry['quality']['type'])
                : null;

            $breedId = isset($entry['stats']['breed_id'])
                ? (int) $entry['stats']['breed_id']
                : null;

            $creatureDisplayId = isset($entry['species']['creature_display']['id'])
                ? (int) $entry['species']['creature_display']['id']
                : null;

            $out[] = new CharacterPet(
                petId: $petId,
                speciesId: (int) ($entry['species']['id'] ?? 0),
                name: (string) ($entry['name'] ?? $entry['species']['name'] ?? 'Unknown'),
                level: (int) ($entry['level'] ?? 1),
                breedId: $breedId,
                quality: $quality,
                isFavorite: (bool) ($entry['is_favorite'] ?? false),
                creatureDisplayId: $creatureDisplayId,
            );
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterPetMapperTest.php
```

Expected: PASS (4 tests, all green).

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Mappers/CharacterPetMapper.php tests/Unit/Blizzard/Mappers/CharacterPetMapperTest.php
git commit -m "Add CharacterPetMapper with unit tests"
```

---

## Task 15: `CharacterToyMapper` — TDD

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/CharacterToyMapperTest.php`
- Create: `app/Blizzard/Mappers/CharacterToyMapper.php`

The Blizzard `/collections/toys` endpoint shape is:
```json
{
  "toys": [
    { "toy": { "id": 54343, "name": "X-52 Rocket Pack", "key": { "href": "..." } } }
  ]
}
```

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Blizzard/Mappers/CharacterToyMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterToyMapper;
use Tests\TestCase;

class CharacterToyMapperTest extends TestCase
{
    public function test_maps_toy_entries(): void
    {
        $payload = [
            'toys' => [
                ['toy' => ['id' => 54343, 'name' => 'X-52 Rocket Pack']],
                ['toy' => ['id' => 88589, 'name' => 'Hearthstone Toy']],
            ],
        ];

        $dtos = (new CharacterToyMapper)->map($payload);

        $this->assertCount(2, $dtos);
        $this->assertSame(54343, $dtos[0]->toyId);
        $this->assertSame('X-52 Rocket Pack', $dtos[0]->name);
        $this->assertSame(88589, $dtos[1]->toyId);
    }

    public function test_returns_empty_for_null_or_missing_toys(): void
    {
        $this->assertSame([], (new CharacterToyMapper)->map(null));
        $this->assertSame([], (new CharacterToyMapper)->map([]));
        $this->assertSame([], (new CharacterToyMapper)->map(['toys' => []]));
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $payload = ['toys' => [
            ['toy' => ['id' => 0, 'name' => 'broken']],
            ['toy' => ['id' => 5, 'name' => 'good']],
        ]];

        $dtos = (new CharacterToyMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(5, $dtos[0]->toyId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterToyMapperTest.php
```

Expected: FAIL with `Class "App\Blizzard\Mappers\CharacterToyMapper" not found`.

- [ ] **Step 3: Write the mapper**

Create `app/Blizzard/Mappers/CharacterToyMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterToy;

class CharacterToyMapper
{
    /**
     * @return CharacterToy[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['toys'] ?? [] as $entry) {
            $id = (int) ($entry['toy']['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $out[] = new CharacterToy(
                toyId: $id,
                name: (string) ($entry['toy']['name'] ?? 'Unknown'),
            );
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterToyMapperTest.php
```

Expected: PASS (3 tests, all green).

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Mappers/CharacterToyMapper.php tests/Unit/Blizzard/Mappers/CharacterToyMapperTest.php
git commit -m "Add CharacterToyMapper with unit tests"
```

---

## Task 16: `BlizzardProfileClient::getCharacterCollections()`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardProfileClient.php`

Three endpoints, one parallel fan-out via `Http::pool()`. No chunking needed — three requests is well below the 80 req/s budget under Horizon's max concurrency, and the whole pool runs once per slice.

- [ ] **Step 1: Add the method**

In `app/Blizzard/Client/BlizzardProfileClient.php`, after `getCharacterRaidEncounters()` (around line 220), add:

```php
    /**
     * Fetch the three collections endpoints in parallel.
     *
     * @return array{mounts: ?array, pets: ?array, toys: ?array}
     */
    public function getCharacterCollections(string $realm, string $name): array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $basePath = "/profile/wow/character/{$realm}/{$name}/collections";
        $token = $this->tokenManager->getToken($this->region);
        $namespace = $this->namespace();
        $baseUrl = $this->baseUrl();
        $timeout = (int) config('blizzard.timeouts.character_pool', 20);

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('mounts')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/mounts"),

            $pool->as('pets')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/pets"),

            $pool->as('toys')
                ->withToken($token)
                ->baseUrl($baseUrl)
                ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
                ->timeout($timeout)
                ->connectTimeout(5)
                ->get("{$basePath}/toys"),
        ]);

        return [
            'mounts' => $responses['mounts']->successful() ? $responses['mounts']->json() : null,
            'pets' => $responses['pets']->successful() ? $responses['pets']->json() : null,
            'toys' => $responses['toys']->successful() ? $responses['toys']->json() : null,
        ];
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
git commit -m "Add getCharacterCollections fan-out to BlizzardProfileClient"
```

---

## Task 17: Add config keys — staleness threshold + sync feature flag

**Files:**
- Modify: `config/blizzard.php`

- [ ] **Step 1: Add `collections` to the staleness block**

In `config/blizzard.php`, locate the `staleness.character` block (lines 33–40 in the current file). Add the `collections` key after the `raids` line:

```php
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
            'collections' => (int) env('BLIZZARD_STALE_CHARACTER_COLLECTIONS', 86400),
        ],
```

Default 24h: collections rarely change between sessions (a new mount drops once in a while), and the row volume per character can be large — keep the refresh budget low.

- [ ] **Step 2: Add `collections_enabled` to the `sync` block**

In the same file, locate the `sync` block (lines 68–73). Add `collections_enabled` after the `raids_enabled` line:

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'collections_enabled' => (bool) env('BLIZZARD_SYNC_COLLECTIONS_ENABLED', false),
    ],
```

**Default `false`** per spec §5 — operators ramp by setting `BLIZZARD_SYNC_COLLECTIONS_ENABLED=true` after merge.

- [ ] **Step 3: Verify config loads**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "echo config('blizzard.staleness.character.collections') . ' / ' . (config('blizzard.sync.collections_enabled') ? 'on' : 'off');"
```

Expected: `86400 / off`.

- [ ] **Step 4: Commit**

```bash
git add config/blizzard.php
git commit -m "Add collections staleness threshold and feature flag (default off)"
```

---

## Task 18: Wire the sync slice into `SyncCharacterData`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

The slice fetches all three collections via the single client method, then runs all three upsert/delete-missing blocks inside **one** `DB::transaction` so a partial failure rolls back consistently.

- [ ] **Step 1: Add use statements**

In `app/Blizzard/Jobs/SyncCharacterData.php`, add the new use statements alphabetically with the existing ones:

```php
use App\Blizzard\Mappers\CharacterMountMapper;
use App\Blizzard\Mappers\CharacterPetMapper;
use App\Blizzard\Mappers\CharacterToyMapper;
```

and add the model imports:

```php
use App\Models\CharacterMount;
use App\Models\CharacterPet;
use App\Models\CharacterToy;
```

- [ ] **Step 2: Add the three mapper params to `handle()` signature**

In the same file, extend the `handle()` method signature to accept the three new mappers. Replace:

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
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

with:

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
        CharacterMountMapper $mountMapper,
        CharacterPetMapper $petMapper,
        CharacterToyMapper $toyMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

- [ ] **Step 3: Dispatch the slice from the Full block**

In the same file, locate the `Full depth` block:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
        }
```

and add the new call as the final entry:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncCollections($client, $mountMapper, $petMapper, $toyMapper, $character);
        }
```

- [ ] **Step 4: Add the `syncCollections()` method**

After `syncRaidEncounters()` (and before `failed()`) in the same file, add:

```php
    private function syncCollections(
        BlizzardProfileClient $client,
        CharacterMountMapper $mountMapper,
        CharacterPetMapper $petMapper,
        CharacterToyMapper $toyMapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.collections_enabled')) {
            return;
        }

        try {
            $bodies = $client->getCharacterCollections($this->realm, $this->name);

            $mountDtos = $mountMapper->map($bodies['mounts']);
            $petDtos = $petMapper->map($bodies['pets']);
            $toyDtos = $toyMapper->map($bodies['toys']);

            DB::transaction(function () use ($character, $mountDtos, $petDtos, $toyDtos) {
                // Mounts
                $keepMounts = [];
                foreach ($mountDtos as $dto) {
                    CharacterMount::updateOrCreate(
                        ['character_id' => $character->id, 'mount_id' => $dto->mountId],
                        ['name' => $dto->name, 'is_useable' => $dto->isUseable],
                    );
                    $keepMounts[] = $dto->mountId;
                }
                CharacterMount::where('character_id', $character->id)
                    ->when($keepMounts !== [], fn ($q) => $q->whereNotIn('mount_id', $keepMounts))
                    ->delete();

                // Pets
                $keepPets = [];
                foreach ($petDtos as $dto) {
                    CharacterPet::updateOrCreate(
                        ['character_id' => $character->id, 'pet_id' => $dto->petId],
                        [
                            'species_id' => $dto->speciesId,
                            'name' => $dto->name,
                            'level' => $dto->level,
                            'breed_id' => $dto->breedId,
                            'quality' => $dto->quality,
                            'is_favorite' => $dto->isFavorite,
                            'creature_display_id' => $dto->creatureDisplayId,
                        ],
                    );
                    $keepPets[] = $dto->petId;
                }
                CharacterPet::where('character_id', $character->id)
                    ->when($keepPets !== [], fn ($q) => $q->whereNotIn('pet_id', $keepPets))
                    ->delete();

                // Toys
                $keepToys = [];
                foreach ($toyDtos as $dto) {
                    CharacterToy::updateOrCreate(
                        ['character_id' => $character->id, 'toy_id' => $dto->toyId],
                        ['name' => $dto->name],
                    );
                    $keepToys[] = $dto->toyId;
                }
                CharacterToy::where('character_id', $character->id)
                    ->when($keepToys !== [], fn ($q) => $q->whereNotIn('toy_id', $keepToys))
                    ->delete();

                $character->update(['collections_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Failed to sync collections for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 5: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "Wire syncCollections slice into SyncCharacterData (gated, default off)"
```

---

## Task 19: Add `isCollectionsStale()` to the staleness OR-chain in `CharacterService`

**Files:**
- Modify: `app/Services/CharacterService.php`

- [ ] **Step 1: Extend the `$anySliceStale` chain**

In `app/Services/CharacterService.php`, replace:

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
            || $character->isCollectionsStale();
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
git commit -m "Include isCollectionsStale in CharacterService staleness OR-chain"
```

---

## Task 20: Eager-load collections in `CharacterController`

**Files:**
- Modify: `app/Http/Controllers/CharacterController.php`

- [ ] **Step 1: Extend the `load` call**

In `app/Http/Controllers/CharacterController.php`, locate the line:

```php
        $result->load(['guild', 'dungeonRuns.members', 'pvpBrackets', 'professions', 'raidEncounterKills']);
```

and replace it with:

```php
        $result->load(['guild', 'dungeonRuns.members', 'pvpBrackets', 'professions', 'raidEncounterKills', 'mounts', 'pets', 'toys']);
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Http/Controllers/CharacterController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/CharacterController.php
git commit -m "Eager-load collections relations on character show"
```

---

## Task 21: `MountResource`

**Files:**
- Create: `app/Http/Resources/MountResource.php`

- [ ] **Step 1: Write the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'mount_id' => (int) $this->mount_id,
            'name' => $this->name,
            'is_useable' => (bool) $this->is_useable,
        ];
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/MountResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/MountResource.php
git commit -m "Add MountResource"
```

---

## Task 22: `PetResource`

**Files:**
- Create: `app/Http/Resources/PetResource.php`

- [ ] **Step 1: Write the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'pet_id' => (int) $this->pet_id,
            'species_id' => (int) $this->species_id,
            'name' => $this->name,
            'level' => (int) $this->level,
            'breed_id' => $this->breed_id !== null ? (int) $this->breed_id : null,
            'quality' => $this->quality,
            'is_favorite' => (bool) $this->is_favorite,
            'creature_display_id' => $this->creature_display_id !== null ? (int) $this->creature_display_id : null,
        ];
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/PetResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/PetResource.php
git commit -m "Add PetResource"
```

---

## Task 23: `ToyResource`

**Files:**
- Create: `app/Http/Resources/ToyResource.php`

- [ ] **Step 1: Write the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'toy_id' => (int) $this->toy_id,
            'name' => $this->name,
        ];
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/ToyResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/ToyResource.php
git commit -m "Add ToyResource"
```

---

## Task 24: Surface collections in `CharacterResource` + `meta.freshness.collections`

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php`

- [ ] **Step 1: Add `mounts`, `pets`, `toys` to `toArray()`**

In `app/Http/Resources/CharacterResource.php`, locate the line:

```php
            'dungeon_runs' => DungeonRunResource::collection($this->whenLoaded('dungeonRuns')),
```

and add the three new keys directly after it:

```php
            'dungeon_runs' => DungeonRunResource::collection($this->whenLoaded('dungeonRuns')),
            'mounts' => MountResource::collection($this->whenLoaded('mounts')),
            'pets' => PetResource::collection($this->whenLoaded('pets')),
            'toys' => ToyResource::collection($this->whenLoaded('toys')),
```

- [ ] **Step 2: Add the freshness key to `with()`**

In the same file, locate the `freshness` block in `with()`:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                ],
```

and add `collections` after `raids`:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                    'collections' => $this->freshnessFor('collections_synced_at', 'collections'),
                ],
```

- [ ] **Step 3: Add the use statements**

At the top of the file, alongside the existing imports, add:

```php
use App\Http\Resources\MountResource;
use App\Http\Resources\PetResource;
use App\Http\Resources\ToyResource;
```

(If your IDE prefers grouped/sorted imports, run pint at the end of this plan — Task 33 — and it will normalize.)

- [ ] **Step 4: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/CharacterResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/CharacterResource.php
git commit -m "Surface mounts/pets/toys + collections freshness in CharacterResource"
```

---

## Task 25: Update `blizzard:backfill-slices` for the new `*_synced_at` column

**Files:**
- Modify: `app/Console/Commands/BlizzardBackfillSlices.php` (file name guess — verify)

This task assumes the existing artisan command (added in `359c6c1` per `CLAUDE.md`) lives at `app/Console/Commands/BlizzardBackfillSlices.php`. If the file is at a different path, locate it with `grep -rl 'blizzard:backfill-slices' app/Console/`.

- [ ] **Step 1: Locate the command**

Run:
```bash
grep -rl 'blizzard:backfill-slices' app/Console/
```

Expected: a single file path. Note it for the next step.

- [ ] **Step 2: Add `collections_synced_at` to the null-check OR-chain**

Open the file from Step 1. It will contain a query that checks for any null `*_synced_at` column. The exact form will look something like:

```php
->whereNull('mythics_synced_at')
->orWhereNull('pvp_synced_at')
->orWhereNull('professions_synced_at')
->orWhereNull('raids_synced_at')
```

Add an additional `->orWhereNull('collections_synced_at')` to that chain so collections-empty characters get picked up. If the existing form uses a closure with `where(function ($q) { ... })`, add the new clause inside the same closure to preserve precedence.

- [ ] **Step 3: Verify the command still works**

Run:
```bash
php -l app/Console/Commands/BlizzardBackfillSlices.php
php artisan blizzard:backfill-slices --limit=0
```

Expected: `No syntax errors detected`. The command runs to completion (with `--limit=0` it dispatches no jobs but exercises the query).

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/BlizzardBackfillSlices.php
git commit -m "Include collections_synced_at in backfill-slices null-check chain"
```

---

## Task 26: Extend FE `CharacterResource` type with collections

**Files:**
- Modify: `frontend/src/types/character.ts`

- [ ] **Step 1: Add the three collection types and update `CharacterResource` + `MetaBlock`**

In `frontend/src/types/character.ts`, after the `DungeonRun` interface (around line 119), add:

```ts
export interface Mount {
  mount_id: number
  name: string
  is_useable: boolean
}

export interface Pet {
  pet_id: number
  species_id: number
  name: string
  level: number
  breed_id: number | null
  quality: string | null
  is_favorite: boolean
  creature_display_id: number | null
}

export interface Toy {
  toy_id: number
  name: string
}
```

Then in `CharacterResource` (around lines 121–150), after the `dungeon_runs: DungeonRun[]` line, add the three new fields:

```ts
  dungeon_runs: DungeonRun[]
  mounts: Mount[] | null
  pets: Pet[] | null
  toys: Toy[] | null
```

In `MetaBlock` (around lines 152–162), inside the `freshness` object literal, after the `raids: FreshnessState` line, add:

```ts
    raids: FreshnessState
    collections: FreshnessState
```

- [ ] **Step 2: Type-check**

Run:
```bash
cd frontend && npx vue-tsc -b
```

Expected: no type errors. (If existing FE callsites assume the old `CharacterResource` shape, those callsites typically don't read collections — adding optional new fields shouldn't regress them.)

- [ ] **Step 3: Commit**

```bash
git add frontend/src/types/character.ts
git commit -m "Add Mount/Pet/Toy types and collections fields to CharacterResource"
```

---

## Task 27: FE — implement `MountsSubtab.vue`

**Files:**
- Modify: `frontend/src/pages/character/collections/MountsSubtab.vue`

Per the Wowhead-link decision in the plan header, mounts in v1 render name-only (sortable list with badge for unusable). No tooltip — that requires a per-mount summon-spell lookup deferred out of this slice.

- [ ] **Step 1: Replace the subtab with a real implementation**

Replace the entire contents of `frontend/src/pages/character/collections/MountsSubtab.vue` with:

```vue
<template>
  <div v-if="!hasMounts" class="card bg-base-200 p-8 text-center text-base-content/60">
    <Mountain class="mx-auto mb-2 h-10 w-10 opacity-60" />
    <p>No mounts collected yet.</p>
  </div>
  <div v-else class="flex flex-col gap-3">
    <header class="flex items-center justify-between">
      <h3 class="text-lg font-semibold">Mounts ({{ mounts.length }})</h3>
    </header>
    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <li
        v-for="m in mounts"
        :key="m.mount_id"
        class="card card-compact flex flex-row items-center gap-3 bg-base-200 px-3 py-2"
        :class="{ 'opacity-50': !m.is_useable }"
      >
        <Mountain class="h-5 w-5 shrink-0 opacity-70" />
        <span class="truncate">{{ m.name }}</span>
        <span v-if="!m.is_useable" class="badge badge-ghost badge-sm">unusable</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Mountain } from 'lucide-vue-next'
import { useCharacterContext } from '@/composables/useCharacterContext'

const { character } = useCharacterContext()

const mounts = computed(() => character.value.mounts ?? [])
const hasMounts = computed(() => mounts.value.length > 0)
</script>
```

- [ ] **Step 2: Type-check**

Run:
```bash
cd frontend && npx vue-tsc -b
```

Expected: no type errors.

- [ ] **Step 3: Smoke-test in the dev server**

Run (one terminal):
```bash
cd frontend && npm run dev
```

Visit a character page → Collections → Mounts. With `BLIZZARD_SYNC_COLLECTIONS_ENABLED=false` you should see the empty state. Flip the env to `true`, restart Horizon (`docker compose restart horizon`), refresh the character, and verify mounts render after the next sync cycle.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/character/collections/MountsSubtab.vue
git commit -m "Implement MountsSubtab with name list + count + usable badge"
```

---

## Task 28: FE — implement `PetsSubtab.vue`

**Files:**
- Modify: `frontend/src/pages/character/collections/PetsSubtab.vue`

Per the plan header decision, pets link to Wowhead via `npc={creature_display_id}` when available. For pets where `creature_display_id` is null, fall back to plain text.

- [ ] **Step 1: Replace the subtab with a real implementation**

Replace the entire contents of `frontend/src/pages/character/collections/PetsSubtab.vue` with:

```vue
<template>
  <div v-if="!hasPets" class="card bg-base-200 p-8 text-center text-base-content/60">
    <Cat class="mx-auto mb-2 h-10 w-10 opacity-60" />
    <p>No pets collected yet.</p>
  </div>
  <div v-else class="flex flex-col gap-3">
    <header class="flex items-center justify-between">
      <h3 class="text-lg font-semibold">Pets ({{ pets.length }})</h3>
    </header>
    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <li
        v-for="p in pets"
        :key="p.pet_id"
        class="card card-compact flex flex-row items-center gap-3 bg-base-200 px-3 py-2"
      >
        <Cat class="h-5 w-5 shrink-0 opacity-70" />
        <a
          v-if="p.creature_display_id"
          :data-wowhead="`npc=${p.creature_display_id}`"
          href="#"
          @click.prevent
          class="truncate hover:underline"
        >
          {{ p.name }}
        </a>
        <span v-else class="truncate">{{ p.name }}</span>
        <span class="badge badge-ghost badge-sm ml-auto">L{{ p.level }}</span>
        <span v-if="p.is_favorite" class="badge badge-warning badge-sm">★</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Cat } from 'lucide-vue-next'
import { useCharacterContext } from '@/composables/useCharacterContext'

const { character } = useCharacterContext()

const pets = computed(() => character.value.pets ?? [])
const hasPets = computed(() => pets.value.length > 0)
</script>
```

- [ ] **Step 2: Type-check**

Run:
```bash
cd frontend && npx vue-tsc -b
```

Expected: no type errors.

- [ ] **Step 3: Smoke-test**

Visit Collections → Pets in a browser with the dev server running and the BE flag enabled. Hover a pet name to confirm the Wowhead tooltip fires (the parent `CharacterDetailLayout` already calls `useWowheadRefresh` when the response loads).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/character/collections/PetsSubtab.vue
git commit -m "Implement PetsSubtab with Wowhead npc tooltips, level badge, favorite star"
```

---

## Task 29: FE — implement `ToysSubtab.vue`

**Files:**
- Modify: `frontend/src/pages/character/collections/ToysSubtab.vue`

Toys link via `item={toy_id}` — Wowhead Power widget supports it directly.

- [ ] **Step 1: Replace the subtab with a real implementation**

Replace the entire contents of `frontend/src/pages/character/collections/ToysSubtab.vue` with:

```vue
<template>
  <div v-if="!hasToys" class="card bg-base-200 p-8 text-center text-base-content/60">
    <Sparkles class="mx-auto mb-2 h-10 w-10 opacity-60" />
    <p>No toys collected yet.</p>
  </div>
  <div v-else class="flex flex-col gap-3">
    <header class="flex items-center justify-between">
      <h3 class="text-lg font-semibold">Toys ({{ toys.length }})</h3>
    </header>
    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <li
        v-for="t in toys"
        :key="t.toy_id"
        class="card card-compact flex flex-row items-center gap-3 bg-base-200 px-3 py-2"
      >
        <Sparkles class="h-5 w-5 shrink-0 opacity-70" />
        <a
          :data-wowhead="`item=${t.toy_id}`"
          href="#"
          @click.prevent
          class="truncate hover:underline"
        >
          {{ t.name }}
        </a>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Sparkles } from 'lucide-vue-next'
import { useCharacterContext } from '@/composables/useCharacterContext'

const { character } = useCharacterContext()

const toys = computed(() => character.value.toys ?? [])
const hasToys = computed(() => toys.value.length > 0)
</script>
```

- [ ] **Step 2: Type-check**

Run:
```bash
cd frontend && npx vue-tsc -b
```

Expected: no type errors.

- [ ] **Step 3: Smoke-test**

Visit Collections → Toys with dev server + BE flag on. Hover a toy name to confirm the Wowhead item tooltip fires.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/character/collections/ToysSubtab.vue
git commit -m "Implement ToysSubtab with Wowhead item tooltips"
```

---

## Task 30: FE — show counts on the Collections tabstrip

**Files:**
- Modify: `frontend/src/pages/character/CharacterCollectionsTab.vue`

The Collections parent shows a tabstrip with three labels. Add the count after each label, derived from the loaded character.

- [ ] **Step 1: Read the current `subTabs` computed and rewrite it**

Replace the entire contents of `frontend/src/pages/character/CharacterCollectionsTab.vue` with:

```vue
<template>
  <div class="flex flex-col gap-4">
    <CharacterTabStrip :tabs="subTabs" />
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Mountain, Cat, Sparkles } from 'lucide-vue-next'
import CharacterTabStrip, {
  type TabDescriptor,
} from '@/components/character/CharacterTabStrip.vue'
import { useCharacterContext } from '@/composables/useCharacterContext'

const route = useRoute()
const { character } = useCharacterContext()

const mountCount = computed(() => character.value.mounts?.length ?? 0)
const petCount = computed(() => character.value.pets?.length ?? 0)
const toyCount = computed(() => character.value.toys?.length ?? 0)

const subTabs = computed<TabDescriptor[]>(() => {
  const params = route.params
  return [
    {
      label: mountCount.value ? `Mounts (${mountCount.value})` : 'Mounts',
      to: { name: 'character-collections-mounts', params },
      icon: Mountain,
    },
    {
      label: petCount.value ? `Pets (${petCount.value})` : 'Pets',
      to: { name: 'character-collections-pets', params },
      icon: Cat,
    },
    {
      label: toyCount.value ? `Toys (${toyCount.value})` : 'Toys',
      to: { name: 'character-collections-toys', params },
      icon: Sparkles,
    },
  ]
})
</script>
```

If `TabDescriptor` does not accept a dynamic `label` string, the existing strip already accepts a string-typed `label` (current code passes `'Mounts'` etc., which is `string`), so this change is shape-compatible.

- [ ] **Step 2: Type-check**

Run:
```bash
cd frontend && npx vue-tsc -b
```

Expected: no type errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/character/CharacterCollectionsTab.vue
git commit -m "Show mount/pet/toy counts on Collections tabstrip"
```

---

## Task 31: Endpoint integration test — collections shape

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

Extend the existing retail-character endpoint test to assert the new top-level keys + a populated-data assertion when running against a fixture whose account has any collected mount / pet / toy.

- [ ] **Step 1: Extend the JSON-structure assertion**

In `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`, locate the `assertJsonStructure` block in `test_retail_endpoint_returns_valid_response` and add the three keys + the freshness key:

```php
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'realm',
                'region',
                'game_version',
                'level',
                'class_id',
                'race_id',
                'faction',
                'average_item_level',
                'equipped_item_level',
                'active_specialization',
                'talent_loadout_code',
                'media' => ['avatar', 'inset', 'main'],
                'talents' => ['class', 'spec', 'hero', 'pvp'],
                'equipment',
                'mythic_plus_rating',
                'pvp_brackets',
                'professions',
                'raid_progress',
                'mounts',
                'pets',
                'toys',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids', 'collections'],
            ],
        ]);
```

- [ ] **Step 2: Add a populated-data assertion guarded on the slice flag**

After the existing equipment/talents assertions in the same test method, add:

```php
        // Only assert collections shape when the slice is enabled.
        if (config('blizzard.sync.collections_enabled')) {
            $mounts = $response->json('data.mounts');
            $pets = $response->json('data.pets');
            $toys = $response->json('data.toys');

            $this->assertIsArray($mounts);
            $this->assertIsArray($pets);
            $this->assertIsArray($toys);

            foreach ($mounts as $i => $m) {
                $this->assertArrayHasKey('mount_id', $m, "mounts[{$i}] missing mount_id");
                $this->assertArrayHasKey('name', $m, "mounts[{$i}] missing name");
                $this->assertArrayHasKey('is_useable', $m, "mounts[{$i}] missing is_useable");
            }
            foreach ($pets as $i => $p) {
                foreach (['pet_id', 'species_id', 'name', 'level', 'is_favorite'] as $key) {
                    $this->assertArrayHasKey($key, $p, "pets[{$i}] missing {$key}");
                }
            }
            foreach ($toys as $i => $t) {
                $this->assertArrayHasKey('toy_id', $t, "toys[{$i}] missing toy_id");
                $this->assertArrayHasKey('name', $t, "toys[{$i}] missing name");
            }
        }
```

- [ ] **Step 3: Run the default suite to confirm structure assertion holds**

Run:
```bash
composer test
```

Expected: green. The new keys are present (always, via `whenLoaded` returning `[]` when relations not loaded; the assertion uses `assertJsonStructure` which only checks key presence, not type).

- [ ] **Step 4: Run integration suite (optional, with credentials + flag enabled)**

If `BLIZZARD_CLIENT_ID` is set and a fixture is filled in, run:

```bash
BLIZZARD_SYNC_COLLECTIONS_ENABLED=true composer test:integration
```

Expected: tests pass; populated assertions hit if the fixture account has any collection rows.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "Assert collections shape in RetailCharacterEndpointTest"
```

---

## Task 32: Verify default test suite stays green

**Files:** none (verification only)

- [ ] **Step 1: Run the full default suite**

Run:
```bash
composer test
```

Expected: green. All Plan 4 / earlier-plan tests still pass; the three new mapper unit tests pass; no integration tests run by default.

- [ ] **Step 2: If anything fails, stop and triage**

A green default suite is the gate before the documentation update. If a test fails:
1. Read the failing assertion.
2. If it's a regression caused by this plan, fix it via a new `git commit` rather than amending. Likely culprits: a forgotten import in `CharacterResource.php`, a `casts()` mismatch in `Character.php`, or a missing migration on the test database.
3. Re-run `composer test`.

- [ ] **Step 3: Commit any fixes** (only if you had to make one)

```bash
git status --short
git diff
git add -A
git commit -m "Fix Plan 4 collections slice test regression"
```

If nothing failed, this step is a no-op.

---

## Task 33: Run pint + update `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`
- Run: `./vendor/bin/pint`

- [ ] **Step 1: Run pint**

Run:
```bash
./vendor/bin/pint
git status --short
```

Expected: pint normalizes code style. Verify only files this plan touched (and pint-only whitespace changes) appear.

- [ ] **Step 2: Commit pint fixes (if any)**

```bash
git add -A
git diff --cached --stat
git commit -m "Run pint on Plan 4 collections slice" || echo "nothing to commit"
```

- [ ] **Step 3: Update `CLAUDE.md` architecture notes**

In `CLAUDE.md`, find the existing per-slice Full-sync paragraph (the one beginning **"Per-slice Full sync with feature flags."**). Add this new paragraph immediately after it:

```
- **Collections slice (Plan 4).** `SyncCharacterData::syncCollections()` fetches `/collections/mounts`, `/collections/pets`, `/collections/toys` in one parallel `Http::pool()` and writes to three sub-tables (`character_mounts`, `character_pets`, `character_toys`) inside one `DB::transaction` with delete-missing semantics. A single `collections_synced_at` column on `characters` tracks freshness; a single `BLIZZARD_SYNC_COLLECTIONS_ENABLED` flag (default `false`) gates the entire slice. Pets persist `creature_display_id` so the FE can link via Wowhead's `npc=` widget; toys persist `toy_id` for `item=` linking; mounts persist only id + name + is_useable (summon-spell enrichment is a follow-up — the journal mount id is neither item nor spell on its own).
```

- [ ] **Step 4: Commit CLAUDE.md update**

```bash
git add CLAUDE.md
git commit -m "Document Plan 4 collections slice in CLAUDE.md"
```

- [ ] **Step 5: Final verification**

Run:
```bash
composer test
git log --oneline -35
php artisan migrate:status | tail -10
```

Expected:
- Default suite green.
- Git log shows ~30 commits from this plan.
- The four new migrations (`add_collections_synced_at`, `create_character_mounts`, `create_character_pets`, `create_character_toys`) are listed as `Ran`.

---

## Done criteria for the collections slice

- [ ] `collections_synced_at` column exists on `characters`; the three sub-tables (`character_mounts`, `character_pets`, `character_toys`) exist with their unique indexes and FK cascades.
- [ ] `CharacterMount`, `CharacterPet`, `CharacterToy` Eloquent models exist with proper casts and `BelongsTo` relations.
- [ ] `Character` model has `mounts()`, `pets()`, `toys()` `HasMany` relations and `isCollectionsStale()`.
- [ ] Three DTOs (`App\Blizzard\DTO\CharacterMount` / `CharacterPet` / `CharacterToy`) are present.
- [ ] Three mappers exist with passing unit tests (10 test methods total across the three).
- [ ] `BlizzardProfileClient::getCharacterCollections()` returns `['mounts' => ?array, 'pets' => ?array, 'toys' => ?array]` from one parallel `Http::pool()`.
- [ ] `SyncCharacterData::syncCollections()` runs all three upsert/delete-missing blocks in one transaction, gated on `config('blizzard.sync.collections_enabled')`, default off.
- [ ] `CharacterService::getByIdentity()`'s OR-chain includes `isCollectionsStale()`.
- [ ] `CharacterController::show()` eager-loads `mounts`, `pets`, `toys`.
- [ ] `MountResource`, `PetResource`, `ToyResource` exist; `CharacterResource` exposes them via `whenLoaded` and adds `meta.freshness.collections`.
- [ ] `blizzard:backfill-slices` checks `collections_synced_at IS NULL`.
- [ ] FE `CharacterResource` type carries `mounts`, `pets`, `toys` arrays + `freshness.collections`.
- [ ] `MountsSubtab.vue`, `PetsSubtab.vue`, `ToysSubtab.vue` render real data; `CharacterCollectionsTab.vue` shows counts.
- [ ] Pets and toys link to Wowhead via `npc=` and `item=` respectively; mounts render name-only with usable badge.
- [ ] `RetailCharacterEndpointTest` asserts the three new top-level keys and the new freshness key.
- [ ] `composer test` is green; `composer test:integration` (if credentials + filled fixtures + `BLIZZARD_SYNC_COLLECTIONS_ENABLED=true`) hits collections assertions.
- [ ] `CLAUDE.md` documents the collections slice paragraph.
- [ ] All commits live on `feature/character-collections-and-stats` and the slice is ready to PR (or to be split per sub-table if a sub-PR strategy is chosen later).
