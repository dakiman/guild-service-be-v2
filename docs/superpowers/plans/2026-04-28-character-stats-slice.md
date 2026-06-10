# Plan — Character Stats Slice (Plan 4, slice 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md` — decisions LOCKED 2026-04-28.

**Goal:** Ship the first slice of "Plan 4" — fetch Blizzard's `/character-stats` endpoint per character, persist the payload as a JSONB column, expose it through `CharacterResource`, and replace the `CharacterStatsCardPlaceholder` on the character summary tab with a real stats card. Above-the-fold visibility, smallest blast radius, lowest line count of the five Plan 4 slices.

**Architecture:** Follows the established Plan 2 per-slice pattern (CLAUDE.md §"Per-slice Full sync with feature flags"). One new column (`stats` JSONB), one new staleness column (`stats_synced_at`), one new sync method `SyncCharacterData::syncStats()` gated on `config('blizzard.sync.stats_enabled')` (env `BLIZZARD_SYNC_STATS_ENABLED`, default **false** per spec §5). Failure is isolated — `try { DB::transaction { upsert + stats_synced_at = now() } } catch` — and never aborts the other Full-depth slices. `Character::isStatsStale()` joins the OR-chain in `CharacterService::getByIdentity()` so a never-synced or stale-stats character triggers `SyncDepth::Full`. The persisted shape is the raw Blizzard `character-stats` body **stripped of the `_links` and `character` envelope keys** — the FE renders specific fields off the JSONB and gets new fields for free as Blizzard adds them, with no migration churn (per decision §2.1).

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL JSONB, Laravel Horizon, Redis. Frontend: Vue 3, Vite, TypeScript, Tailwind/DaisyUI. Tests: PHPUnit (`composer test`) + e2e endpoint test (`composer test:integration`).

**Out of this plan (deferred):**
- Other Plan 4 slices (titles, reputations, collections, achievements) — separate plan docs.
- Stat-trend charts / historical stat snapshots — only the latest snapshot is persisted.
- Class- or spec-conditional rendering rules on the FE — the FE shows whichever fields are non-null in the payload and ignores the rest.

**Deploy-ready at the end of:** this plan, behind a flag (`BLIZZARD_SYNC_STATS_ENABLED=false` by default). Schema is additive (nullable JSONB column) and safe for live deploy. Flip the env flag to `true` to enable production sync.

---

## Task 1: Create the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify working tree is clean and create the branch**

The spec §2.4 specifies branching off `master` once Plan 2 has merged. If `feature/plan-2-retail-character-enrichment` is unmerged, rebase or branch off it instead and note that in the PR description.

Run:
```bash
cd backend
git status --short
git fetch origin
git checkout -b feature/character-collections-and-stats origin/master
```

Expected: clean working tree, new branch checked out from `origin/master`.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: top of `master` — Plan 2 commits visible (`e4dbadf` and friends), Plan 1 schema present.

---

## Task 2: Migration — add `stats` JSONB + `stats_synced_at` to `characters`

**Files:**
- Create: `database/migrations/2026_04_28_100001_add_stats_to_characters.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_04_28_100001_add_stats_to_characters.php`:

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
        Schema::table('characters', function (Blueprint $table) {
            $table->jsonb('stats')->nullable()->after('equipment');
            $table->timestamp('stats_synced_at')->nullable()->after('raids_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['stats', 'stats_synced_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "echo Schema::hasColumn('characters', 'stats') ? 'stats:ok ' : 'stats:missing '; echo Schema::hasColumn('characters', 'stats_synced_at') ? 'ts:ok' : 'ts:missing';"
```

Expected: `stats:ok ts:ok`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100001_add_stats_to_characters.php
git commit -m "Add stats JSONB and stats_synced_at to characters"
```

---

## Task 3: Extend `config/blizzard.php` with stats flags

**Files:**
- Modify: `config/blizzard.php`

- [ ] **Step 1: Add `stats` staleness threshold + `stats_enabled` sync flag**

In `config/blizzard.php`, replace the `staleness.character` block with:

```php
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
            'stats' => (int) env('BLIZZARD_STALE_CHARACTER_STATS', 900),
        ],
```

In the same file, replace the `sync` block with:

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'stats_enabled' => (bool) env('BLIZZARD_SYNC_STATS_ENABLED', false),
    ],
```

The default is **false** per spec §5 — slices ramp via env per ops decision, not on by default.

- [ ] **Step 2: Verify config loads**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "echo config('blizzard.staleness.character.stats').'|'.var_export(config('blizzard.sync.stats_enabled'), true);"
```

Expected: `900|false`.

- [ ] **Step 3: Commit**

```bash
git add config/blizzard.php
git commit -m "Add stats staleness threshold and stats_enabled sync flag"
```

---

## Task 4: Update `Character` model — fillable, casts, `isStatsStale()`

**Files:**
- Modify: `app/Models/Character.php`

- [ ] **Step 1: Add `stats` and `stats_synced_at` to `$fillable`**

In `app/Models/Character.php`, replace the `$fillable` array with:

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
        'mythic_plus_rating_color',
        'active_specialization',
        'talent_loadout_code',
        'media',
        'talents',
        'equipment',
        'stats',
        'recruitment',
        'num_of_searches',
        'last_searched_at',
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
        'stats_synced_at',
    ];
```

- [ ] **Step 2: Add casts for `stats` (array) and `stats_synced_at` (datetime)**

Replace the `casts()` method body with:

```php
    protected function casts(): array
    {
        return [
            'media' => 'array',
            'talents' => 'array',
            'equipment' => 'array',
            'stats' => 'array',
            'mythic_plus_rating_by_spec' => 'array',
            'recruitment' => 'boolean',
            'mythics_synced_at' => 'datetime',
            'pvp_synced_at' => 'datetime',
            'professions_synced_at' => 'datetime',
            'raids_synced_at' => 'datetime',
            'stats_synced_at' => 'datetime',
            'last_searched_at' => 'datetime',
            'race_id' => 'integer',
            'class_id' => 'integer',
            'level' => 'integer',
            'mythic_plus_rating' => 'integer',
            'num_of_searches' => 'integer',
        ];
    }
```

- [ ] **Step 3: Add `isStatsStale()` helper**

Append to `app/Models/Character.php` (just below `isRaidsStale()`):

```php
    public function isStatsStale(): bool
    {
        return ! $this->stats_synced_at
            || $this->stats_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.stats');
    }
```

- [ ] **Step 4: Verify**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "\$c = App\\Models\\Character::factory()->make(['stats' => ['strength' => 100]]); echo gettype(\$c->stats).'|'.\$c->stats['strength'];"
```

Expected: `array|100`.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Character.php
git commit -m "Add stats column, cast, and isStatsStale helper to Character model"
```

---

## Task 5: Create `CharacterStats` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterStats.php`

The DTO is a thin wrapper around the persisted associative array — Blizzard's `/character-stats` payload contains ~80 fields with class- and spec-conditional shapes (e.g. `mana_regen` only for casters; `weapon_skill` only Classic). Storing the full payload as JSONB (per spec §2.1) avoids a brittle column-per-field schema. The DTO carries the normalized payload + a few load-bearing top-level fields the FE renders prominently.

- [ ] **Step 1: Write the DTO**

Create `app/Blizzard/DTO/CharacterStats.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterStats
{
    /**
     * Full normalized stats payload, indexed by Blizzard field name.
     *
     * Common fields (not exhaustive — Blizzard adds/changes these per patch):
     *   health, power, power_type, strength, agility, intellect, stamina,
     *   melee_crit, melee_haste, mastery, bonus_armor, lifesteal, versatility,
     *   versatility_damage_done_bonus, versatility_healing_done_bonus,
     *   versatility_damage_taken_bonus, avoidance, attack_power,
     *   main_hand_damage_min, main_hand_damage_max, main_hand_speed, main_hand_dps,
     *   off_hand_damage_min, off_hand_damage_max, off_hand_speed, off_hand_dps,
     *   spell_power, spell_penetration, spell_crit, mana_regen, mana_regen_combat,
     *   armor, dodge, parry, block, ranged_crit, ranged_haste, etc.
     *
     * Each entry is either a scalar (int / float) or an object of the form:
     *   { value: number, effective: number, rating: int, rating_bonus: float }
     *
     * The FE picks which fields to render; the BE does not enforce a schema.
     *
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public array $fields,
        public ?int $health = null,
        public ?int $power = null,
        public ?string $powerType = null,
        public ?int $strength = null,
        public ?int $agility = null,
        public ?int $intellect = null,
        public ?int $stamina = null,
    ) {}
}
```

- [ ] **Step 2: Verify the class loads**

Run:
```bash
php -l app/Blizzard/DTO/CharacterStats.php
php -r "require 'vendor/autoload.php'; \$d = new App\\Blizzard\\DTO\\CharacterStats(fields: ['strength' => 100], strength: 100); echo \$d->strength === 100 ? 'ok' : 'bad';"
```

Expected: `No syntax errors detected` and `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterStats.php
git commit -m "Add CharacterStats DTO carrying full Blizzard /character-stats payload"
```

---

## Task 6: Create `CharacterStatsMapper`

**Files:**
- Create: `app/Blizzard/Mappers/CharacterStatsMapper.php`

- [ ] **Step 1: Write the mapper**

Create `app/Blizzard/Mappers/CharacterStatsMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterStats;

class CharacterStatsMapper
{
    /**
     * Strip Blizzard's `_links` and `character` envelope keys; keep the rest
     * of the payload verbatim. The FE picks which fields it cares about.
     *
     * @param  array<string, mixed>  $data
     */
    public function map(array $data): CharacterStats
    {
        $fields = $data;
        unset($fields['_links'], $fields['character']);

        return new CharacterStats(
            fields: $fields,
            health: isset($fields['health']) ? (int) $fields['health'] : null,
            power: isset($fields['power']) ? (int) $fields['power'] : null,
            powerType: isset($fields['power_type']['name'])
                ? (string) $fields['power_type']['name']
                : null,
            strength: $this->primaryStat($fields, 'strength'),
            agility: $this->primaryStat($fields, 'agility'),
            intellect: $this->primaryStat($fields, 'intellect'),
            stamina: $this->primaryStat($fields, 'stamina'),
        );
    }

    /**
     * Blizzard nests primary stats as { base, effective }.
     *
     * @param  array<string, mixed>  $fields
     */
    private function primaryStat(array $fields, string $key): ?int
    {
        if (! isset($fields[$key])) {
            return null;
        }

        $entry = $fields[$key];
        if (is_array($entry) && isset($entry['effective'])) {
            return (int) $entry['effective'];
        }
        if (is_numeric($entry)) {
            return (int) $entry;
        }

        return null;
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Mappers/CharacterStatsMapper.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Add a quick unit smoke test**

Create `tests/Unit/Blizzard/CharacterStatsMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard;

use App\Blizzard\Mappers\CharacterStatsMapper;
use Tests\TestCase;

class CharacterStatsMapperTest extends TestCase
{
    public function test_strips_envelope_keys(): void
    {
        $mapper = new CharacterStatsMapper;
        $dto = $mapper->map([
            '_links' => ['self' => ['href' => 'x']],
            'character' => ['name' => 'TestCharacter'],
            'health' => 1000,
            'strength' => ['base' => 50, 'effective' => 75],
            'mastery' => 12.5,
        ]);

        $this->assertArrayNotHasKey('_links', $dto->fields);
        $this->assertArrayNotHasKey('character', $dto->fields);
        $this->assertSame(1000, $dto->health);
        $this->assertSame(75, $dto->strength);
        $this->assertSame(12.5, $dto->fields['mastery']);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $mapper = new CharacterStatsMapper;
        $dto = $mapper->map(['health' => 500]);

        $this->assertNull($dto->strength);
        $this->assertNull($dto->agility);
        $this->assertNull($dto->intellect);
        $this->assertSame(500, $dto->health);
    }
}
```

- [ ] **Step 4: Run and verify the test**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/CharacterStatsMapperTest.php
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Mappers/CharacterStatsMapper.php tests/Unit/Blizzard/CharacterStatsMapperTest.php
git commit -m "Add CharacterStatsMapper with unit test"
```

---

## Task 7: Add `BlizzardProfileClient::getCharacterStats()`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardProfileClient.php`

- [ ] **Step 1: Add the method**

In `app/Blizzard/Client/BlizzardProfileClient.php`, immediately after the `getCharacterRaidEncounters()` method (around line 220, before `getGuildData()`), insert:

```php
    public function getCharacterStats(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/character-stats");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
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
git commit -m "Add BlizzardProfileClient::getCharacterStats"
```

---

## Task 8: Wire `syncStats()` into `SyncCharacterData`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Import `CharacterStatsMapper`**

Near the top of `app/Blizzard/Jobs/SyncCharacterData.php`, add the import alongside the other mapper imports (preserve alphabetical-ish order — slot it between `CharacterSpecializationMapper` and `MythicPlusMapper`):

```php
use App\Blizzard\Mappers\CharacterSpecializationMapper;
use App\Blizzard\Mappers\CharacterStatsMapper;
use App\Blizzard\Mappers\MythicPlusMapper;
```

- [ ] **Step 2: Inject `CharacterStatsMapper` into `handle()`**

Replace the `handle()` method's signature so it accepts the new mapper. Replace:

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
        CharacterStatsMapper $statsMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

- [ ] **Step 3: Call `syncStats()` from the Full-depth block**

In the same file, replace:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
        }
    }
```

with:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncStats($client, $statsMapper, $character);
        }
    }
```

- [ ] **Step 4: Implement `syncStats()`**

In the same file, append (after `syncRaidEncounters()` and before `failed()`):

```php
    private function syncStats(
        BlizzardProfileClient $client,
        CharacterStatsMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.stats_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterStats($this->realm, $this->name);

            DB::transaction(function () use ($character, $mapper, $data) {
                $character->update([
                    'stats' => $data === null ? null : $mapper->map($data)->fields,
                    'stats_synced_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('Failed to sync character stats', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }
```

A 404 from `/character-stats` (e.g. low-level character) writes `stats = null` and updates `stats_synced_at` so the slice is considered fresh — matching the delete-missing semantics of other slices (CLAUDE.md §"Delete-missing semantics").

- [ ] **Step 5: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "Add syncStats slice to SyncCharacterData (gated, failure-isolated)"
```

---

## Task 9: Extend `CharacterService` OR-chain

**Files:**
- Modify: `app/Services/CharacterService.php`

- [ ] **Step 1: Add `isStatsStale()` to the OR-chain**

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
            || $character->isStatsStale();
```

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Services/CharacterService.php
php artisan test --filter=CharacterServiceTest 2>&1 | tail -10 || true
```

Expected: no syntax errors. (CharacterServiceTest may not exist — that's fine; we cover this path in the integration test.)

- [ ] **Step 3: Commit**

```bash
git add app/Services/CharacterService.php
git commit -m "Add isStatsStale to CharacterService staleness OR-chain"
```

---

## Task 10: Update `BackfillSlices` command to consider stats

**Files:**
- Modify: `app/Console/Commands/BackfillSlices.php`

- [ ] **Step 1: Add `stats_synced_at` null check**

In `app/Console/Commands/BackfillSlices.php`, replace:

```php
            ->where(function ($q) {
                $q->whereNull('mythics_synced_at')
                    ->orWhereNull('pvp_synced_at')
                    ->orWhereNull('professions_synced_at')
                    ->orWhereNull('raids_synced_at');
            })
```

with:

```php
            ->where(function ($q) {
                $q->whereNull('mythics_synced_at')
                    ->orWhereNull('pvp_synced_at')
                    ->orWhereNull('professions_synced_at')
                    ->orWhereNull('raids_synced_at')
                    ->orWhereNull('stats_synced_at');
            })
```

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Console/Commands/BackfillSlices.php
php artisan blizzard:backfill-slices --limit=0
```

Expected: no syntax errors; command runs and prints `Dispatched 0 Full syncs.` (no characters dispatched at limit=0).

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/BackfillSlices.php
git commit -m "Include stats_synced_at in BackfillSlices null-check"
```

---

## Task 11: Expose `stats` and `meta.freshness.stats` in `CharacterResource`

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php`

- [ ] **Step 1: Add `stats` to `toArray()`**

In `app/Http/Resources/CharacterResource.php`, replace:

```php
            'media' => $this->media,
            'talents' => $this->talents,
            'equipment' => $this->equipment ?? [],
```

with:

```php
            'media' => $this->media,
            'talents' => $this->talents,
            'equipment' => $this->equipment ?? [],
            'stats' => $this->stats,
```

(Insert `'stats'` directly after `'equipment'`. Order matters for FE TypeScript-key ordering and for snapshot stability in tests.)

- [ ] **Step 2: Add `stats_synced_at` next to the existing synced-at fields**

In the same file, replace:

```php
            'mythics_synced_at' => $this->mythics_synced_at?->toIso8601String(),
            'synced_at' => $this->updated_at?->toIso8601String(),
```

with:

```php
            'mythics_synced_at' => $this->mythics_synced_at?->toIso8601String(),
            'stats_synced_at' => $this->stats_synced_at?->toIso8601String(),
            'synced_at' => $this->updated_at?->toIso8601String(),
```

- [ ] **Step 3: Add `stats` to `meta.freshness`**

In the same file, replace:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                ],
```

with:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                    'stats' => $this->freshnessFor('stats_synced_at', 'stats'),
                ],
```

- [ ] **Step 4: Verify**

Run:
```bash
php -l app/Http/Resources/CharacterResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/CharacterResource.php
git commit -m "Expose stats + stats_synced_at + meta.freshness.stats in CharacterResource"
```

---

## Task 12: Extend `RetailCharacterEndpointTest` to assert the stats shape

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Add `stats` and `meta.freshness.stats` to the asserted JSON structure**

In `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`, replace:

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
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids'],
            ],
        ]);
```

with:

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
                'stats',
                'mythic_plus_rating',
                'pvp_brackets',
                'professions',
                'raid_progress',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids', 'stats'],
            ],
        ]);
```

- [ ] **Step 2: Add a stats-shape assertion under the geared_main fixture**

In the same test method, immediately before the `$talents = $response->json('data.talents');` line, insert:

```php
        $stats = $response->json('data.stats');
        if ($stats !== null) {
            $this->assertIsArray($stats, 'stats should be an associative array when present');
            $this->assertArrayHasKey('health', $stats, 'stats payload missing health');
            $this->assertArrayNotHasKey('_links', $stats, 'stats payload should have _links envelope stripped');
            $this->assertArrayNotHasKey('character', $stats, 'stats payload should have character envelope stripped');
        }
```

(Permissive — `null` is a valid response for low-level fixtures, but if the geared_main fixture is filled in the assertions exercise the happy path.)

- [ ] **Step 3: Run the default suite to confirm no regressions**

Run:
```bash
composer test
```

Expected: default suite still green (integration tests remain skipped without credentials).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "Assert stats shape and meta.freshness.stats in RetailCharacterEndpointTest"
```

---

## Task 13: FE — extend TypeScript types

**Files:**
- Modify: `frontend/src/types/character.ts`

- [ ] **Step 1: Add the `CharacterStats` interface**

In `frontend/src/types/character.ts`, immediately after the `EquipmentItem` interface (~line 37), insert:

```ts
export interface CharacterStats {
  // Top-level numeric fields the FE renders prominently.
  health?: number
  power?: number
  power_type?: { id?: number; name?: string; type?: string }

  // Primary stats — Blizzard nests as { base, effective }.
  strength?: { base: number; effective: number }
  agility?: { base: number; effective: number }
  intellect?: { base: number; effective: number }
  stamina?: { base: number; effective: number }

  // Secondaries — Blizzard nests as { value, effective, rating, rating_bonus }.
  melee_crit?: { value: number; effective: number; rating: number; rating_bonus: number }
  melee_haste?: { value: number; effective: number; rating: number; rating_bonus: number }
  mastery?: { value: number; effective: number; rating: number; rating_bonus: number }
  versatility_damage_done_bonus?: number
  versatility_healing_done_bonus?: number
  versatility_damage_taken_bonus?: number

  // Defensive
  armor?: { base: number; effective: number }
  dodge?: { value: number; rating: number; rating_bonus: number }
  parry?: { value: number; rating: number; rating_bonus: number }
  block?: { value: number; rating: number; rating_bonus: number }

  // Offensive
  attack_power?: number
  spell_power?: number
  spell_crit?: { value: number; rating: number; rating_bonus: number }

  // Forward-compatible: any other Blizzard-emitted key.
  [key: string]: unknown
}
```

- [ ] **Step 2: Add `stats` and `stats_synced_at` to `CharacterResource`**

In the same file, replace the `CharacterResource` interface block (lines 121-150) with:

```ts
export interface CharacterResource {
  id: number
  name: string
  realm: string
  region: Region
  game_version: GameVersion
  gender: string
  faction: Faction
  race_id: number
  class_id: number
  level: number
  achievement_points: number
  average_item_level: number
  equipped_item_level: number
  active_specialization: string | null
  talent_loadout_code: string | null
  mythic_plus_rating: MythicPlusRating | null
  media: { avatar: string; inset: string; main: string }
  talents: CharacterTalents
  equipment: EquipmentItem[]
  stats: CharacterStats | null
  pvp_brackets: PvpBracketStats[] | null
  professions: Profession[] | null
  raid_progress: RaidEncounterProgress[] | null
  recruitment: boolean
  guild: GuildSummary | null
  dungeon_runs: DungeonRun[]
  last_searched_at: string | null
  mythics_synced_at: string | null
  stats_synced_at: string | null
  synced_at: string | null
}
```

- [ ] **Step 3: Add `stats` to `MetaBlock.freshness`**

In the same file, replace:

```ts
export interface MetaBlock {
  game_version: GameVersion
  forced_refresh: boolean
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
  }
}
```

with:

```ts
export interface MetaBlock {
  game_version: GameVersion
  forced_refresh: boolean
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
    stats: FreshnessState
  }
}
```

- [ ] **Step 4: Verify the FE type-checker is happy**

Run from `frontend/`:
```bash
cd frontend
npx vue-tsc --noEmit
```

Expected: type-check passes (warnings about other unrelated code may pre-exist; the only new errors should be in files this plan modifies, and at this step there are none).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types/character.ts
git commit -m "Add CharacterStats type and stats freshness key to FE types"
```

---

## Task 14: FE — create `CharacterStatsCard` component

**Files:**
- Create: `frontend/src/components/character/CharacterStatsCard.vue`

The card mirrors the visual treatment of `PvpRatingsCard` (used as the sibling in the same `lg:grid-cols-2` row of `CharacterSummaryTab`). Tailwind/DaisyUI classes use existing `ma-` prefixed theme tokens — see `CharacterStatsCardPlaceholder.vue` for the exact `ma-card` / `ma-text-heading` / `text-ma-muted` class names.

- [ ] **Step 1: Write the component**

Create `frontend/src/components/character/CharacterStatsCard.vue`:

```vue
<template>
  <div class="ma-card p-6">
    <h3 class="ma-text-heading mb-4 text-lg">Detailed stats</h3>

    <div v-if="!stats" class="text-ma-muted/80 text-sm">
      Stats not available yet — refresh shortly.
    </div>

    <div v-else class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
      <StatRow label="Health" :value="formatInt(stats.health)" />
      <StatRow label="Primary" :value="primaryStatLabel" />
      <StatRow label="Crit" :value="formatPercent(stats.melee_crit?.value)" />
      <StatRow label="Haste" :value="formatPercent(stats.melee_haste?.value)" />
      <StatRow label="Mastery" :value="formatPercent(stats.mastery?.value)" />
      <StatRow label="Versatility" :value="versatilityLabel" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h } from 'vue'
import type { CharacterStats } from '@/types/character'

const props = defineProps<{ stats: CharacterStats | null }>()

const primaryStatLabel = computed(() => {
  if (!props.stats) return '—'
  const candidates: Array<{ label: string; entry?: { effective: number } }> = [
    { label: 'Strength', entry: props.stats.strength },
    { label: 'Agility', entry: props.stats.agility },
    { label: 'Intellect', entry: props.stats.intellect },
  ]
  const best = candidates
    .filter((c): c is { label: string; entry: { effective: number } } => c.entry !== undefined)
    .sort((a, b) => b.entry.effective - a.entry.effective)[0]
  return best ? `${best.label} ${formatInt(best.entry.effective)}` : '—'
})

const versatilityLabel = computed(() => {
  const damage = props.stats?.versatility_damage_done_bonus
  if (typeof damage !== 'number') return '—'
  return `${damage.toFixed(2)}%`
})

function formatInt(value: number | null | undefined): string {
  if (typeof value !== 'number') return '—'
  return new Intl.NumberFormat().format(Math.round(value))
}

function formatPercent(value: number | null | undefined): string {
  if (typeof value !== 'number') return '—'
  return `${value.toFixed(2)}%`
}

const StatRow = defineComponent({
  name: 'StatRow',
  props: { label: { type: String, required: true }, value: { type: String, required: true } },
  setup(slotProps) {
    return () =>
      h('div', { class: 'flex items-center justify-between' }, [
        h('span', { class: 'text-ma-muted/80' }, slotProps.label),
        h('span', { class: 'ma-text-heading font-medium' }, slotProps.value),
      ])
  },
})
</script>
```

(Inline `StatRow` definition keeps the component self-contained — see `CharacterStatsCardPlaceholder.vue` for the prevailing convention of single-file components without separate child files for one-off layouts.)

- [ ] **Step 2: Verify type-check**

Run from `frontend/`:
```bash
cd frontend
npx vue-tsc --noEmit
```

Expected: type-check passes.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/character/CharacterStatsCard.vue
git commit -m "Add CharacterStatsCard component"
```

---

## Task 15: FE — replace placeholder in `CharacterSummaryTab`

**Files:**
- Modify: `frontend/src/pages/character/CharacterSummaryTab.vue`
- Delete (after replacement): `frontend/src/components/character/CharacterStatsCardPlaceholder.vue`

- [ ] **Step 1: Swap the import and the usage**

Replace the entire contents of `frontend/src/pages/character/CharacterSummaryTab.vue` with:

```vue
<template>
  <div class="flex flex-col gap-6">
    <MirroredEquipmentLayout
      :equipment="character.equipment"
      :render-url="character.media.main"
      :character-name="character.name"
    />
    <ProfessionsStrip :entries="character.professions" />
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <CharacterStatsCard :stats="character.stats" />
      <PvpRatingsCard :brackets="character.pvp_brackets" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useCharacterContext } from '@/composables/useCharacterContext'
import MirroredEquipmentLayout from '@/components/character/MirroredEquipmentLayout.vue'
import ProfessionsStrip from '@/components/character/ProfessionsStrip.vue'
import CharacterStatsCard from '@/components/character/CharacterStatsCard.vue'
import PvpRatingsCard from '@/components/character/PvpRatingsCard.vue'

const { character } = useCharacterContext()
</script>
```

- [ ] **Step 2: Verify nothing else imports the placeholder**

Run from the repo root:
```bash
grep -rn "CharacterStatsCardPlaceholder" frontend/src
```

Expected: no matches.

- [ ] **Step 3: Delete the placeholder file**

Run:
```bash
rm frontend/src/components/character/CharacterStatsCardPlaceholder.vue
```

- [ ] **Step 4: Verify type-check**

Run from `frontend/`:
```bash
cd frontend
npx vue-tsc --noEmit
```

Expected: type-check passes.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/character/CharacterSummaryTab.vue frontend/src/components/character/CharacterStatsCardPlaceholder.vue
git commit -m "Replace CharacterStatsCardPlaceholder with real CharacterStatsCard"
```

---

## Task 16: FE — add `Stats` chip to `FreshnessChips`

**Files:**
- Modify: `frontend/src/components/feedback/FreshnessChips.vue`

`FreshnessChips` has a hardcoded `slices` array — it does **not** auto-pick-up new freshness keys (despite what the spec §5 implies). Add the `stats` entry explicitly.

- [ ] **Step 1: Add `stats` to the slice list**

In `frontend/src/components/feedback/FreshnessChips.vue`, replace:

```ts
const slices: Array<{ key: keyof MetaBlock['freshness']; label: string }> = [
  { key: 'profile', label: 'Profile' },
  { key: 'mythic_plus', label: 'M+' },
  { key: 'pvp', label: 'PvP' },
  { key: 'professions', label: 'Profs' },
  { key: 'raids', label: 'Raids' },
]
```

with:

```ts
const slices: Array<{ key: keyof MetaBlock['freshness']; label: string }> = [
  { key: 'profile', label: 'Profile' },
  { key: 'mythic_plus', label: 'M+' },
  { key: 'pvp', label: 'PvP' },
  { key: 'professions', label: 'Profs' },
  { key: 'raids', label: 'Raids' },
  { key: 'stats', label: 'Stats' },
]
```

- [ ] **Step 2: Verify type-check**

Run from `frontend/`:
```bash
cd frontend
npx vue-tsc --noEmit
```

Expected: type-check passes.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/feedback/FreshnessChips.vue
git commit -m "Show Stats chip in FreshnessChips"
```

---

## Task 17: FE build smoke test

**Files:** none (verification only)

- [ ] **Step 1: Run the FE production build**

Run from `frontend/`:
```bash
cd frontend
npm run build
```

Expected: build completes successfully. `vue-tsc -b` runs first (per CLAUDE.md) and emits no type errors; Vite then produces `dist/` artifacts.

- [ ] **Step 2: Visual smoke check (manual, optional but recommended)**

Run:
```bash
npm run dev
```

Open `http://localhost:5173/characters/eu/<realm>/<known-character>/summary` and confirm:
- The "Detailed stats" card shows either real numbers or the "Stats not available yet — refresh shortly." copy.
- A `Stats: awaiting`/`stale`/`fresh` chip appears alongside the other freshness chips.

This step is manual — do not block the plan on it; commit the doc-side changes first if the build is green.

---

## Task 18: Run pint, update `CLAUDE.md`, final verification

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Run pint**

From `backend/`, run:
```bash
cd backend
./vendor/bin/pint
git status --short
```

Expected: pint formats any new files; only files this plan touched should appear (plus pint-only whitespace changes).

- [ ] **Step 2: Commit pint fixes (if any)**

```bash
git add -A
git diff --cached --stat
git commit -m "Run pint on stats-slice changes" || echo "nothing to commit"
```

- [ ] **Step 3: Update `backend/CLAUDE.md`**

In `backend/CLAUDE.md`, in the "Architecture → Blizzard Module" section, find the bullet:

```
- **ProactiveSyncCharacters tier 1 dispatches Full.** Tier 1 (popular chars, every 30 min) refreshes all slices.
```

and insert the following bullet immediately **before** it:

```
- **Stats slice.** `stats` JSONB column on `characters` carries the Blizzard `/character-stats` payload (envelope keys `_links` and `character` stripped). The slice is gated on `BLIZZARD_SYNC_STATS_ENABLED` (default false) and tracks freshness via `stats_synced_at`. A 404 from Blizzard writes `stats = null` and updates `stats_synced_at` (delete-missing semantics).
```

- [ ] **Step 4: Commit the CLAUDE.md update**

```bash
git add backend/CLAUDE.md
git commit -m "Document stats slice in CLAUDE.md"
```

- [ ] **Step 5: Final verification**

Run:
```bash
cd backend
composer test
git log --oneline -25
php artisan migrate:status | tail -10
```

Expected:
- BE default test suite passes.
- Git log shows ~16-18 small commits from this plan.
- The `2026_04_28_100001_add_stats_to_characters` migration is listed as `Ran`.

Run from `frontend/`:
```bash
cd ../frontend
npm run build
```

Expected: production build succeeds.

---

## Done criteria

- [ ] `stats` JSONB and `stats_synced_at` columns exist on `characters`.
- [ ] `BLIZZARD_SYNC_STATS_ENABLED` env flag (default false) and `stats` staleness threshold (default 900s) are wired through `config/blizzard.php`.
- [ ] `Character::isStatsStale()` exists and is part of `CharacterService::getByIdentity()`'s OR-chain.
- [ ] `BlizzardProfileClient::getCharacterStats()` fetches `/profile/wow/character/{realm}/{name}/character-stats` and returns `null` on 404.
- [ ] `CharacterStats` DTO + `CharacterStatsMapper` exist; mapper unit test passes.
- [ ] `SyncCharacterData::syncStats()` runs on `SyncDepth::Full`, gated on `config('blizzard.sync.stats_enabled')`, with try/catch around `DB::transaction { update stats + stats_synced_at }`.
- [ ] `BackfillSlices` checks `stats_synced_at` null.
- [ ] `CharacterResource` exposes `data.stats`, `data.stats_synced_at`, and `meta.freshness.stats`.
- [ ] `RetailCharacterEndpointTest` asserts the new structure.
- [ ] FE `CharacterResource` and `MetaBlock` types include `stats` and `freshness.stats`.
- [ ] `CharacterStatsCard` component renders, replaces `CharacterStatsCardPlaceholder`, and the placeholder file is deleted.
- [ ] `FreshnessChips` displays a `Stats` chip.
- [ ] `npm run build` (FE) succeeds; `composer test` (BE) is green.
- [ ] `CLAUDE.md` documents the new slice.
- [ ] Branch `feature/character-collections-and-stats` is ready for the next slice (Titles) to land on top of, or to PR on its own.
