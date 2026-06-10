# Plan 1 — Schema Foundation + Equipment/Specialization Enrichment

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-22-v1-feature-parity-and-enrichment-design.md`

**Goal:** Put the full schema foundation in place and enrich the retail character response so equipped items carry the Wowhead-ready shape (`bonus`, `gems`, `enchantments`, `set_id`, `stats`, `name`) and specializations carry PvP talents plus Blizzard's talent loadout code.

**Architecture:** Strictly additive. New migrations add `game_version` + per-slice staleness columns to `characters`, and create three empty sub-tables (`character_pvp_brackets`, `character_professions`, `raid_encounter_kills`) that Plan 2 will populate. The `EquippedItem` DTO, `CharacterSpecialization` DTO, and their mappers are extended. `SyncCharacterData` persists the richer shape on the existing `Standard` and `Full` depth flows — no new jobs, no new endpoints, no behavior change to callers.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL with JSONB columns, Laravel Horizon, Redis. Tests are e2e-only against the real Blizzard API, gated on credential env vars.

**Out of this plan (deferred to Plan 2/3):** populating `character_pvp_brackets` / `character_professions` / `raid_encounter_kills` rows, mythic+ rating, Classic read-through, `?refresh=1` support.

**Deploy-ready at the end of:** this plan. Schema migration is safe because all new columns are NULL-able or have safe defaults; the unique-index swap is safe because no `game_version='classic'` rows can exist yet.

---

## Task 1: Create the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify working tree is clean, create branch**

Run:
```bash
git status --short
git checkout -b feature/plan-1-schema-and-equipment-enrichment
```

Expected: a new branch is created. If working tree shows untracked `.claude/` or modified `docker-compose.yml`, leave those alone — they are not in this plan's scope.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: the most recent commit is the spec commit `Add design spec for v1 feature parity + Blizzard API enrichment`.

---

## Task 2: Migration — add `game_version` + per-slice staleness columns to `characters`

**Files:**
- Create: `database/migrations/2026_04_22_100001_add_game_version_and_staleness_to_characters.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_04_22_100001_add_game_version_and_staleness_to_characters.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('game_version', 20)->default('retail')->after('region');
            $table->smallInteger('mythic_plus_rating')->nullable()->after('equipped_item_level');
            $table->jsonb('mythic_plus_rating_by_spec')->nullable()->after('mythic_plus_rating');
            $table->string('talent_loadout_code', 255)->nullable()->after('active_specialization');
            $table->timestamp('pvp_synced_at')->nullable()->after('mythics_synced_at');
            $table->timestamp('professions_synced_at')->nullable()->after('pvp_synced_at');
            $table->timestamp('raids_synced_at')->nullable()->after('professions_synced_at');
        });

        // Swap the unique index to include game_version.
        // Safe: no game_version='classic' rows can exist at this point, so existing
        // (name, realm, region) uniqueness still holds for (name, realm, region, 'retail').
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_name_realm_region_unique');
            $table->unique(['name', 'realm', 'region', 'game_version'], 'characters_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_identity_unique');
            $table->unique(['name', 'realm', 'region'], 'characters_name_realm_region_unique');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn([
                'game_version',
                'mythic_plus_rating',
                'mythic_plus_rating_by_spec',
                'talent_loadout_code',
                'pvp_synced_at',
                'professions_synced_at',
                'raids_synced_at',
            ]);
        });
    }
};
```

- [ ] **Step 2: Run the migration and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "print_r(Schema::getColumnListing('characters'));"
```

Expected: migration runs without error. The column listing includes `game_version`, `mythic_plus_rating`, `mythic_plus_rating_by_spec`, `talent_loadout_code`, `pvp_synced_at`, `professions_synced_at`, `raids_synced_at`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_22_100001_add_game_version_and_staleness_to_characters.php
git commit -m "Add game_version and per-slice staleness columns to characters"
```

---

## Task 3: Migration — create `character_pvp_brackets`

**Files:**
- Create: `database/migrations/2026_04_22_100002_create_character_pvp_brackets_table.php`

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
        Schema::create('character_pvp_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('bracket', 32);
            $table->smallInteger('rating')->default(0);
            $table->integer('season_won')->default(0);
            $table->integer('season_lost')->default(0);
            $table->integer('season_played')->default(0);
            $table->integer('weekly_won')->default(0);
            $table->integer('weekly_lost')->default(0);
            $table->integer('weekly_played')->default(0);
            $table->string('tier_name', 50)->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'bracket'], 'character_pvp_brackets_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_pvp_brackets');
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_pvp_brackets'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_22_100002_create_character_pvp_brackets_table.php
git commit -m "Add character_pvp_brackets table"
```

---

## Task 4: Migration — create `character_professions`

**Files:**
- Create: `database/migrations/2026_04_22_100003_create_character_professions_table.php`

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
        Schema::create('character_professions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('profession_id');
            $table->string('profession_name', 100);
            $table->string('tier_name', 100);
            $table->smallInteger('skill_points')->default(0);
            $table->smallInteger('max_skill_points')->default(0);
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['character_id', 'profession_id', 'tier_name'], 'character_professions_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_professions');
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_professions'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_22_100003_create_character_professions_table.php
git commit -m "Add character_professions table"
```

---

## Task 5: Migration — create `raid_encounter_kills`

**Files:**
- Create: `database/migrations/2026_04_22_100004_create_raid_encounter_kills_table.php`

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
        Schema::create('raid_encounter_kills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('expansion_name', 100);
            $table->integer('instance_id');
            $table->string('instance_name', 150);
            $table->integer('encounter_id');
            $table->string('encounter_name', 150);
            $table->string('difficulty', 16);
            $table->integer('completed_count')->default(0);
            $table->bigInteger('last_kill_timestamp')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'encounter_id', 'difficulty'], 'raid_encounter_kills_unique');
            $table->index(['character_id', 'difficulty']);
            $table->index(['instance_id', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_encounter_kills');
    }
};
```

- [ ] **Step 2: Run and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('raid_encounter_kills'));"
```

Expected: `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_22_100004_create_raid_encounter_kills_table.php
git commit -m "Add raid_encounter_kills table"
```

---

## Task 6: Add `GameVersion` enum

**Files:**
- Create: `app/Enums/GameVersion.php`

- [ ] **Step 1: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum GameVersion: string
{
    case Retail = 'retail';
    case Classic = 'classic';

    public function profileNamespace(string $region): string
    {
        return match ($this) {
            self::Retail => "profile-{$region}",
            self::Classic => "profile-classic-{$region}",
        };
    }
}
```

- [ ] **Step 2: Verify the enum loads**

Run:
```bash
php artisan tinker --execute "echo App\Enums\GameVersion::Retail->profileNamespace('eu');"
```

Expected: `profile-eu`.

- [ ] **Step 3: Commit**

```bash
git add app/Enums/GameVersion.php
git commit -m "Add GameVersion enum for namespace dispatch"
```

---

## Task 7: Extend `config/blizzard.php` with new staleness keys

**Files:**
- Modify: `config/blizzard.php`

- [ ] **Step 1: Add the three new staleness keys**

Edit `config/blizzard.php`. Replace the `staleness.character` block with:

```php
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
        ],
```

- [ ] **Step 2: Verify config loads**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "echo config('blizzard.staleness.character.professions');"
```

Expected: `21600`.

- [ ] **Step 3: Commit**

```bash
git add config/blizzard.php
git commit -m "Add pvp/professions/raids staleness thresholds"
```

---

## Task 8: Update `Character` model

**Files:**
- Modify: `app/Models/Character.php`

- [ ] **Step 1: Extend `$fillable`**

Replace the `$fillable` array in `app/Models/Character.php` with:

```php
    protected $fillable = [
        'user_id',
        'guild_id',
        'name',
        'realm',
        'region',
        'game_version',
        'gender',
        'faction',
        'race_id',
        'class_id',
        'level',
        'achievement_points',
        'average_item_level',
        'equipped_item_level',
        'mythic_plus_rating',
        'mythic_plus_rating_by_spec',
        'active_specialization',
        'talent_loadout_code',
        'media',
        'talents',
        'equipment',
        'recruitment',
        'num_of_searches',
        'last_searched_at',
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
    ];
```

- [ ] **Step 2: Extend `casts()`**

Replace the `casts()` method body with:

```php
    protected function casts(): array
    {
        return [
            'media' => 'array',
            'talents' => 'array',
            'equipment' => 'array',
            'mythic_plus_rating_by_spec' => 'array',
            'recruitment' => 'boolean',
            'mythics_synced_at' => 'datetime',
            'pvp_synced_at' => 'datetime',
            'professions_synced_at' => 'datetime',
            'raids_synced_at' => 'datetime',
            'last_searched_at' => 'datetime',
            'race_id' => 'integer',
            'class_id' => 'integer',
            'level' => 'integer',
            'mythic_plus_rating' => 'integer',
            'num_of_searches' => 'integer',
        ];
    }
```

- [ ] **Step 3: Verify existing code still works**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "App\Models\Character::factory()->make()->toArray();"
```

Expected: output is an array representation — no fatal errors, no undefined-property errors.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Character.php
git commit -m "Extend Character fillable/casts for new staleness and rating columns"
```

---

## Task 9: Update `CharacterFactory` defaults

**Files:**
- Modify: `database/factories/CharacterFactory.php`

- [ ] **Step 1: Add `game_version` to the factory definition**

In `database/factories/CharacterFactory.php`, add `'game_version' => 'retail',` after the `'region'` line in `definition()`:

```php
            'region' => fake()->randomElement(['eu', 'us', 'kr', 'tw']),
            'game_version' => 'retail',
```

- [ ] **Step 2: Verify factory still works**

Run:
```bash
php artisan tinker --execute "\$c = App\Models\Character::factory()->make(); echo \$c->game_version;"
```

Expected: `retail`.

- [ ] **Step 3: Commit**

```bash
git add database/factories/CharacterFactory.php
git commit -m "Default game_version=retail in CharacterFactory"
```

---

## Task 10: Enrich `EquippedItem` DTO

**Files:**
- Modify: `app/Blizzard/DTO/EquippedItem.php`

- [ ] **Step 1: Replace the DTO with the enriched shape**

Replace the entire contents of `app/Blizzard/DTO/EquippedItem.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class EquippedItem
{
    /**
     * @param  int[]  $bonus          Blizzard `bonus_list` — Wowhead `&bonus=`
     * @param  int[]  $gems           Gem item_ids in socket order — Wowhead `&gems=`
     * @param  int[]  $enchantments   Enchantment ids — Wowhead `&ench=`
     * @param  array<int, array{type: string, value: int, is_negated: bool}>  $stats
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $quality,
        public string $slot,
        public int $itemLevel,
        public array $bonus = [],
        public array $gems = [],
        public array $enchantments = [],
        public ?int $setId = null,
        public array $stats = [],
    ) {}
}
```

- [ ] **Step 2: Verify the class loads**

Run:
```bash
php -r "require 'vendor/autoload.php'; \$i = new App\Blizzard\DTO\EquippedItem(id: 1, name: 'x', quality: 'epic', slot: 'head', itemLevel: 1); echo \$i->setId === null ? 'ok' : 'bad';"
```

Expected: `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/EquippedItem.php
git commit -m "Enrich EquippedItem DTO with sockets/gems/enchants/bonus/stats/set/name"
```

---

## Task 11: Rewrite `CharacterEquipmentMapper` for richness

**Files:**
- Modify: `app/Blizzard/Mappers/CharacterEquipmentMapper.php`

- [ ] **Step 1: Replace the mapper with the rich version**

Replace the entire contents of `app/Blizzard/Mappers/CharacterEquipmentMapper.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\EquippedItem;

class CharacterEquipmentMapper
{
    /**
     * @return EquippedItem[]
     */
    public function map(array $data): array
    {
        $items = [];

        foreach ($data['equipped_items'] ?? [] as $raw) {
            $items[] = new EquippedItem(
                id: (int) ($raw['item']['id'] ?? 0),
                name: (string) ($raw['name'] ?? ''),
                quality: strtolower((string) ($raw['quality']['type'] ?? 'common')),
                slot: strtolower((string) ($raw['slot']['type'] ?? 'unknown')),
                itemLevel: (int) ($raw['level']['value'] ?? 0),
                bonus: $this->mapBonus($raw),
                gems: $this->mapGems($raw),
                enchantments: $this->mapEnchantments($raw),
                setId: $this->mapSetId($raw),
                stats: $this->mapStats($raw),
            );
        }

        return $items;
    }

    /** @return int[] */
    private function mapBonus(array $raw): array
    {
        $bonus = $raw['bonus_list'] ?? [];

        return array_values(array_map('intval', $bonus));
    }

    /** @return int[] */
    private function mapGems(array $raw): array
    {
        $gems = [];
        foreach ($raw['sockets'] ?? [] as $socket) {
            if (isset($socket['item']['id'])) {
                $gems[] = (int) $socket['item']['id'];
            }
        }

        return $gems;
    }

    /** @return int[] */
    private function mapEnchantments(array $raw): array
    {
        $enchants = [];
        foreach ($raw['enchantments'] ?? [] as $e) {
            if (isset($e['enchantment_id'])) {
                $enchants[] = (int) $e['enchantment_id'];
            }
        }

        return $enchants;
    }

    private function mapSetId(array $raw): ?int
    {
        if (isset($raw['set']['item_set']['id'])) {
            return (int) $raw['set']['item_set']['id'];
        }
        if (isset($raw['set']['id'])) {
            return (int) $raw['set']['id'];
        }

        return null;
    }

    /** @return array<int, array{type: string, value: int, is_negated: bool}> */
    private function mapStats(array $raw): array
    {
        $stats = [];
        foreach ($raw['stats'] ?? [] as $s) {
            $stats[] = [
                'type' => strtolower((string) ($s['type']['type'] ?? '')),
                'value' => (int) ($s['value'] ?? 0),
                'is_negated' => (bool) ($s['is_negated'] ?? false),
            ];
        }

        return $stats;
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Mappers/CharacterEquipmentMapper.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/Mappers/CharacterEquipmentMapper.php
git commit -m "Map sockets, gems, enchants, bonus list, stats, set id in CharacterEquipmentMapper"
```

---

## Task 12: Enrich `CharacterSpecialization` DTO

**Files:**
- Modify: `app/Blizzard/DTO/CharacterSpecialization.php`

- [ ] **Step 1: Replace the DTO**

Replace the entire contents of `app/Blizzard/DTO/CharacterSpecialization.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterSpecialization
{
    /**
     * @param  array<int, array{id: int, rank: int}>  $classTalents
     * @param  array<int, array{id: int, rank: int}>  $specTalents
     * @param  array<int, array{id: int, rank: int}>  $heroTalents
     * @param  array<int, array{slot: int, talent_id: int, spell_id: int}>  $pvpTalents
     */
    public function __construct(
        public string $activeSpecialization,
        public array $classTalents = [],
        public array $specTalents = [],
        public array $heroTalents = [],
        public array $pvpTalents = [],
        public ?string $talentLoadoutCode = null,
    ) {}
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/DTO/CharacterSpecialization.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterSpecialization.php
git commit -m "Add pvpTalents and talentLoadoutCode to CharacterSpecialization DTO"
```

---

## Task 13: Extend `CharacterSpecializationMapper` for PvP talents + loadout code

**Files:**
- Modify: `app/Blizzard/Mappers/CharacterSpecializationMapper.php`

- [ ] **Step 1: Replace the mapper**

Replace the entire contents of `app/Blizzard/Mappers/CharacterSpecializationMapper.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterSpecialization;

class CharacterSpecializationMapper
{
    public function map(array $data): CharacterSpecialization
    {
        $activeSpec = (string) ($data['active_specialization']['name'] ?? 'Unknown');
        $loadouts = $data['specializations'] ?? [];

        $classTalents = [];
        $specTalents = [];
        $heroTalents = [];
        $pvpTalents = [];
        $loadoutCode = null;

        foreach ($loadouts as $loadout) {
            if (! isset($loadout['is_active']) || $loadout['is_active'] !== true) {
                continue;
            }

            $classTalents = $this->extractTalents($loadout['selected_class_talents'] ?? []);
            $specTalents = $this->extractTalents($loadout['selected_spec_talents'] ?? []);
            $heroTalents = $this->extractTalents($loadout['selected_hero_talents'] ?? []);
            $pvpTalents = $this->extractPvpTalents($loadout['pvp_talent_slots'] ?? []);
            $loadoutCode = isset($loadout['talent_loadout_code'])
                ? (string) $loadout['talent_loadout_code']
                : null;

            break;
        }

        return new CharacterSpecialization(
            activeSpecialization: $activeSpec,
            classTalents: $classTalents,
            specTalents: $specTalents,
            heroTalents: $heroTalents,
            pvpTalents: $pvpTalents,
            talentLoadoutCode: $loadoutCode,
        );
    }

    /** @return array<int, array{id: int, rank: int}> */
    private function extractTalents(array $talents): array
    {
        $result = [];

        foreach ($talents as $talent) {
            if (isset($talent['id'])) {
                $result[] = [
                    'id' => (int) $talent['id'],
                    'rank' => (int) ($talent['rank'] ?? 1),
                ];
            }
        }

        return $result;
    }

    /** @return array<int, array{slot: int, talent_id: int, spell_id: int}> */
    private function extractPvpTalents(array $slots): array
    {
        $result = [];

        foreach ($slots as $slot) {
            $result[] = [
                'slot' => (int) ($slot['slot_number'] ?? 0),
                'talent_id' => (int) ($slot['selected']['talent']['id'] ?? 0),
                'spell_id' => (int) ($slot['selected']['spell_tooltip']['spell']['id'] ?? 0),
            ];
        }

        return $result;
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Mappers/CharacterSpecializationMapper.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/Mappers/CharacterSpecializationMapper.php
git commit -m "Map PvP talents and talent loadout code in CharacterSpecializationMapper"
```

---

## Task 14: Persist new fields in `SyncCharacterData`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Update equipment persistence to write the full Wowhead shape**

In `app/Blizzard/Jobs/SyncCharacterData.php`, locate the block:

```php
            if ($response['equipment']) {
                $equipment = $equipmentMapper->map($response['equipment']);
                $characterData['equipment'] = array_map(fn ($item) => [
                    'id' => $item->id,
                    'item_level' => $item->itemLevel,
                    'quality' => $item->quality,
                    'slot' => $item->slot,
                ], $equipment);
            }
```

Replace it with:

```php
            if ($response['equipment']) {
                $equipment = $equipmentMapper->map($response['equipment']);
                $characterData['equipment'] = array_map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quality' => $item->quality,
                    'slot' => $item->slot,
                    'item_level' => $item->itemLevel,
                    'bonus' => $item->bonus,
                    'gems' => $item->gems,
                    'enchantments' => $item->enchantments,
                    'set_id' => $item->setId,
                    'stats' => $item->stats,
                ], $equipment);
            }
```

- [ ] **Step 2: Persist the new talent fields + loadout code**

In the same file, locate the block:

```php
            if ($response['specializations']) {
                $spec = $specMapper->map($response['specializations']);
                $characterData['active_specialization'] = $spec->activeSpecialization;
                $characterData['talents'] = [
                    'class' => $spec->classTalents,
                    'spec' => $spec->specTalents,
                    'hero' => $spec->heroTalents,
                ];
            }
```

Replace it with:

```php
            if ($response['specializations']) {
                $spec = $specMapper->map($response['specializations']);
                $characterData['active_specialization'] = $spec->activeSpecialization;
                $characterData['talent_loadout_code'] = $spec->talentLoadoutCode;
                $characterData['talents'] = [
                    'class' => $spec->classTalents,
                    'spec' => $spec->specTalents,
                    'hero' => $spec->heroTalents,
                    'pvp' => $spec->pvpTalents,
                ];
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
git commit -m "Persist Wowhead-ready equipment + PvP talents + loadout code in SyncCharacterData"
```

---

## Task 15: Update `CharacterResource` with new fields

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php`

This task extends the resource so the response surfaces the new persisted fields. Slots that Plan 2 will fill (`mythic_plus_rating`, `pvp_brackets`, `professions`, `raid_progress`) are emitted as `null` today — the key is always present, satisfying the frontend contract; values become populated in Plan 2 without any resource change.

- [ ] **Step 1: Replace the resource**

Replace the entire contents of `app/Http/Resources/CharacterResource.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
            'game_version' => $this->game_version ?? 'retail',
            'gender' => $this->gender,
            'faction' => $this->faction,
            'race_id' => $this->race_id,
            'class_id' => $this->class_id,
            'level' => $this->level,
            'achievement_points' => $this->achievement_points,
            'average_item_level' => $this->average_item_level,
            'equipped_item_level' => $this->equipped_item_level,
            'active_specialization' => $this->active_specialization,
            'talent_loadout_code' => $this->talent_loadout_code,
            'mythic_plus_rating' => $this->mythic_plus_rating !== null
                ? [
                    'rating' => (int) $this->mythic_plus_rating,
                    'per_spec' => $this->mythic_plus_rating_by_spec ?? [],
                ]
                : null,
            'media' => $this->media,
            'talents' => $this->talents,
            'equipment' => $this->equipment ?? [],
            'pvp_brackets' => null,
            'professions' => null,
            'raid_progress' => null,
            'recruitment' => $this->recruitment,
            'guild' => new GuildSummaryResource($this->whenLoaded('guild')),
            'dungeon_runs' => DungeonRunResource::collection($this->whenLoaded('dungeonRuns')),
            'last_searched_at' => $this->last_searched_at?->toIso8601String(),
            'mythics_synced_at' => $this->mythics_synced_at?->toIso8601String(),
            'synced_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'game_version' => $this->game_version ?? 'retail',
                'forced_refresh' => false,
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                ],
            ],
        ];
    }

    private function freshnessFor(string $timestampField, string $configKey): string
    {
        $ts = $this->resource->{$timestampField} ?? null;
        if ($ts === null) {
            return 'never_synced';
        }

        $threshold = (int) config("blizzard.staleness.character.{$configKey}", 900);

        return $ts->diffInSeconds(now()) > $threshold ? 'stale' : 'fresh';
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Http/Resources/CharacterResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/CharacterResource.php
git commit -m "Expose new character fields and meta.freshness in CharacterResource"
```

---

## Task 16: Add throttle middleware to character show routes

**Files:**
- Modify: `routes/api.php`

The `?refresh=1` plumbing itself is Plan 3, but the `throttle:10,1` middleware is the same gate that Plan 3 needs, and adding it now is strictly additive — 10 req/min/IP is above typical human read traffic and will not regress current usage. Plan 3 only flips on behavior behind the already-present middleware.

- [ ] **Step 1: Add the middleware to the show route**

In `routes/api.php`, replace the line:

```php
Route::get('/characters/{region}/{realm}/{character}', [CharacterController::class, 'show'])->name('characters.show');
```

with:

```php
Route::get('/characters/{region}/{realm}/{character}', [CharacterController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('characters.show');
```

- [ ] **Step 2: Verify route registration**

Run:
```bash
php artisan route:list --path=characters --json | php -r "var_dump(json_decode(file_get_contents('php://stdin'), true));" | head -60
```

Expected: the `characters.show` entry includes `throttle:10,1` in its middleware list.

- [ ] **Step 3: Commit**

```bash
git add routes/api.php
git commit -m "Add throttle:10,1 to characters show route"
```

---

## Task 17: Add test infrastructure — `EndpointIntegrationTestCase` + composer script

**Files:**
- Create: `tests/Feature/Endpoints/EndpointIntegrationTestCase.php`
- Modify: `composer.json`

- [ ] **Step 1: Create the base class**

Create the directory and file:

```bash
mkdir -p tests/Feature/Endpoints
```

Write `tests/Feature/Endpoints/EndpointIntegrationTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class EndpointIntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Retail character fixtures. Fill in realm and name per slot; the slot key
     * describes the data shape that character is expected to exercise.
     *
     * @var array<string, array{region: string, realm: string, name: string}>
     */
    public const RETAIL_CHARACTERS = [
        'geared_main'     => ['region' => 'eu', 'realm' => '', 'name' => ''], // sockets + enchants + tier set
        'pvp_player'      => ['region' => 'eu', 'realm' => '', 'name' => ''], // active PvP
        'profession_rich' => ['region' => 'eu', 'realm' => '', 'name' => ''], // 2 primaries + secondaries
        'raider'          => ['region' => 'eu', 'realm' => '', 'name' => ''], // active raider
    ];

    /**
     * @var array<string, array{region: string, realm: string, name: string}>
     */
    public const CLASSIC_CHARACTERS = [
        'vanilla_era'  => ['region' => 'eu', 'realm' => '', 'name' => ''],
        'cata_classic' => ['region' => 'eu', 'realm' => '', 'name' => ''],
    ];

    /**
     * @var array<int, array{region: string, realm: string, name: string}>
     */
    public const GUILDS = [
        ['region' => 'eu', 'realm' => '', 'name' => ''],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('BLIZZARD_CLIENT_ID') || ! env('BLIZZARD_CLIENT_SECRET')) {
            $this->markTestSkipped('Blizzard credentials not configured (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET).');
        }
    }

    /**
     * Skip a single test if its fixture has empty realm or name.
     *
     * @param  array{region: string, realm: string, name: string}  $fixture
     */
    protected function requireFixture(array $fixture, string $slot): void
    {
        if ($fixture['realm'] === '' || $fixture['name'] === '') {
            $this->markTestSkipped("Fixture '{$slot}' has an empty realm or name. Fill it in on EndpointIntegrationTestCase to exercise this test.");
        }
    }
}
```

- [ ] **Step 2: Add `test:integration` composer script**

In `composer.json`, replace the `scripts` block's `"test": [ ... ]` entry so the section reads:

```json
        "test": [
            "@php artisan config:clear --ansi",
            "@php artisan test --exclude-group=integration"
        ],
        "test:integration": [
            "@php artisan config:clear --ansi",
            "@php artisan test --group=integration"
        ],
```

- [ ] **Step 3: Verify base class autoloads**

Run:
```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; echo class_exists('Tests\\Feature\\Endpoints\\EndpointIntegrationTestCase') ? 'ok' : 'bad';"
```

Expected: `ok`.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Endpoints/EndpointIntegrationTestCase.php composer.json
git commit -m "Add e2e endpoint test base class and test:integration composer script"
```

---

## Task 18: Write `RetailCharacterEndpointTest`

**Files:**
- Create: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Write the test class**

Write `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class RetailCharacterEndpointTest extends EndpointIntegrationTestCase
{
    /**
     * @return array<string, array{0: array{region: string, realm: string, name: string}, 1: string}>
     */
    public static function retailCharacterProvider(): array
    {
        $out = [];
        foreach (self::RETAIL_CHARACTERS as $slot => $fixture) {
            $out[$slot] = [$fixture, $slot];
        }

        return $out;
    }

    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_returns_valid_response(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";

        // First call may 202 on cold cache; trigger sync and then poll warm.
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

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
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids'],
            ],
        ]);

        $this->assertSame('retail', $response->json('data.game_version'));

        $equipment = $response->json('data.equipment');
        $this->assertIsArray($equipment);
        $this->assertNotEmpty($equipment, 'equipment array should not be empty for a geared character');

        foreach ($equipment as $i => $item) {
            $this->assertArrayHasKey('id', $item, "equipment[{$i}] missing id");
            $this->assertArrayHasKey('name', $item, "equipment[{$i}] missing name");
            $this->assertArrayHasKey('quality', $item, "equipment[{$i}] missing quality");
            $this->assertArrayHasKey('slot', $item, "equipment[{$i}] missing slot");
            $this->assertArrayHasKey('item_level', $item, "equipment[{$i}] missing item_level");
            $this->assertArrayHasKey('bonus', $item, "equipment[{$i}] missing bonus");
            $this->assertArrayHasKey('gems', $item, "equipment[{$i}] missing gems");
            $this->assertArrayHasKey('enchantments', $item, "equipment[{$i}] missing enchantments");
            $this->assertArrayHasKey('set_id', $item, "equipment[{$i}] missing set_id");
            $this->assertArrayHasKey('stats', $item, "equipment[{$i}] missing stats");
            $this->assertIsArray($item['bonus'], "equipment[{$i}].bonus not array");
            $this->assertIsArray($item['gems'], "equipment[{$i}].gems not array");
            $this->assertIsArray($item['enchantments'], "equipment[{$i}].enchantments not array");
            $this->assertIsArray($item['stats'], "equipment[{$i}].stats not array");
        }

        $talents = $response->json('data.talents');
        $this->assertIsArray($talents);
        $this->assertArrayHasKey('class', $talents);
        $this->assertArrayHasKey('spec', $talents);
        $this->assertArrayHasKey('hero', $talents);
        $this->assertArrayHasKey('pvp', $talents);
    }

    /**
     * Dispatch a sync job synchronously if the character isn't persisted yet,
     * so subsequent GETs return 200 instead of 202.
     */
    private function warmCharacterOrSkip(string $url): void
    {
        // A cold 202 means no data yet; wait for the queue to do the work.
        // Since tests run with queue=sync, the dispatch during the 202 handler
        // completes before the response returns — a second GET sees the row.
        $this->getJson($url);
    }
}
```

- [ ] **Step 2: Verify test file is discoverable**

Run:
```bash
./vendor/bin/phpunit --list-tests tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected: test methods for all four fixture slots are listed (each will be marked-skipped without credentials or fixtures, which is fine).

- [ ] **Step 3: Run the integration tests (with credentials + filled fixtures)**

Only run this step if the developer has set `BLIZZARD_CLIENT_ID` / `BLIZZARD_CLIENT_SECRET` and filled in at least one fixture slot on `EndpointIntegrationTestCase`. Run:

```bash
composer test:integration
```

Expected: tests for filled fixtures pass; tests for empty fixtures mark-skipped cleanly.

- [ ] **Step 4: Run the default suite to confirm it stays green**

Run:
```bash
composer test
```

Expected: the default suite (without the `integration` group) runs to completion with no failures. Integration tests are excluded from this run.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "Add e2e RetailCharacterEndpointTest covering enriched equipment + talents shape"
```

---

## Task 19: Write `GuildEndpointTest` regression

**Files:**
- Create: `tests/Feature/Endpoints/GuildEndpointTest.php`

- [ ] **Step 1: Write the test class**

Write `tests/Feature/Endpoints/GuildEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class GuildEndpointTest extends EndpointIntegrationTestCase
{
    /**
     * @return array<int, array{0: array{region: string, realm: string, name: string}}>
     */
    public static function guildProvider(): array
    {
        return array_map(fn ($g) => [$g], self::GUILDS);
    }

    #[DataProvider('guildProvider')]
    public function test_guild_endpoint_returns_valid_response(array $fixture): void
    {
        $this->requireFixture($fixture, 'guild');

        $url = "/api/v1/guilds/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";

        // Cold cache may 202, second call returns 200 (queue runs sync in tests).
        $this->getJson($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'realm',
                'region',
                'faction',
                'member_count',
                'achievement_points',
            ],
        ]);

        $this->assertSame('eu', $response->json('data.region'));
    }
}
```

- [ ] **Step 2: Verify test is discoverable and default suite still green**

Run:
```bash
./vendor/bin/phpunit --list-tests tests/Feature/Endpoints/GuildEndpointTest.php
composer test
```

Expected: test method is listed; default suite passes (integration excluded).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Endpoints/GuildEndpointTest.php
git commit -m "Add e2e GuildEndpointTest regression"
```

---

## Task 20: Run pint + update `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`
- Run: `./vendor/bin/pint`

- [ ] **Step 1: Run pint to normalize code style**

Run:
```bash
./vendor/bin/pint
git status --short
```

Expected: pint formats any files it wants to; check that only files this plan touched show up (plus pint-only whitespace changes).

- [ ] **Step 2: Commit pint fixes (if any)**

```bash
git add -A
git diff --cached --stat
git commit -m "Run pint on Plan 1 changes" || echo "nothing to commit"
```

- [ ] **Step 3: Update `CLAUDE.md` architecture notes**

Edit `CLAUDE.md`. In the "Architecture" section, under the **Blizzard Module** subsection, find:

```
- **DTO/** — Readonly classes with constructor promotion. Carry only the fields we need from Blizzard's deeply nested responses.
```

and add this paragraph immediately after it:

```
- **Equipment shape.** `EquippedItem` and the persisted `equipment` JSONB carry the Wowhead-ready fields: `id, name, quality, slot, item_level, bonus: int[], gems: int[], enchantments: int[], set_id: ?int, stats: [{type,value,is_negated}]`. The frontend's `WowheadLink.vue` consumes this shape directly — do not transform in controllers.
- **Talent shape.** `talents` JSONB has four branches: `class`, `spec`, `hero`, `pvp`. Retail only; Classic does not populate `talents`. The Blizzard-provided `talent_loadout_code` is a separate top-level column on `characters`, not nested in the JSONB.
- **`game_version` column.** Every character row carries `game_version` ('retail' | 'classic') with unique index `(name, realm, region, game_version)`. Retail is the default; Classic persistence is gated behind a feature flag (Plan 3).
```

- [ ] **Step 4: Commit CLAUDE.md update**

```bash
git add CLAUDE.md
git commit -m "Document Plan 1 architecture additions in CLAUDE.md"
```

- [ ] **Step 5: Final verification of the plan's state**

Run:
```bash
composer test
git log --oneline -25
php artisan migrate:status | tail -15
```

Expected:
- Default test suite passes.
- Git log shows ~20 small commits from the plan.
- All four new migrations are listed as `Ran`.

---

## Done criteria for Plan 1

- [ ] Four new migrations applied; `characters` has `game_version`, three new staleness timestamps, `mythic_plus_rating{,_by_spec}`, `talent_loadout_code`.
- [ ] `character_pvp_brackets`, `character_professions`, `raid_encounter_kills` tables exist (empty; Plan 2 populates).
- [ ] `GameVersion` enum exists and resolves correct namespaces for both cases.
- [ ] `EquippedItem` and `CharacterSpecialization` DTOs carry enriched shapes.
- [ ] `CharacterEquipmentMapper` produces the Wowhead-ready per-item structure from Blizzard payloads.
- [ ] `CharacterSpecializationMapper` emits `pvp` talents array and `talent_loadout_code`.
- [ ] `SyncCharacterData` persists the enriched equipment JSONB and the new talent fields.
- [ ] `CharacterResource` surfaces all new fields; slots Plan 2 will populate are explicitly `null`.
- [ ] `throttle:10,1` middleware is live on the character show route.
- [ ] `EndpointIntegrationTestCase` + `RetailCharacterEndpointTest` + `GuildEndpointTest` exist; `composer test` is green; `composer test:integration` passes for any filled-in fixtures.
- [ ] `CLAUDE.md` documents the new equipment shape, talent shape, and `game_version` column.
- [ ] Feature branch `feature/plan-1-schema-and-equipment-enrichment` is ready to PR.
