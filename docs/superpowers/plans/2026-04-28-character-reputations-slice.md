# Character Reputations Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md` (slice 3, decisions §2.2)

**Goal:** Persist and surface a character's per-faction reputation standing (id, name, standing label, value, max) so `CharacterReputationsTab.vue` can render a faction list with a colored standing badge and a `value/max` progress bar.

**Architecture:** New per-slice Full-sync block in `SyncCharacterData::handle()`, mirroring `syncProfessions()` exactly. New `character_reputations` table + `reputations_synced_at` column on `characters`. New DTO/Mapper/Eloquent model/API resource. Slice is gated behind `BLIZZARD_SYNC_REPUTATIONS_ENABLED` (default **`false`**) so it ships dark and is ramped via env. Delete-missing semantics inside a single `DB::transaction`. FE replaces `EmptyTab` with a sortable faction list grouped by expansion **client-side** (BE returns the flat list — see §"Decisions beyond the spec" below).

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Vue 3 + TS + DaisyUI/Tailwind. Tests use SQLite in-memory with `queue=sync`.

**Sequencing:** Slice 3 of Plan 4 (collections-and-stats). Lands **after** the stats and titles slices. Single PR off `feature/character-collections-and-stats`.

**Out of this plan (deferred to follow-up reputations slice — see §"Deferred to follow-up slice"):** paragon counts, renown levels, faction icons, classic-era reputations.

---

## Task 1: Branch off, verify Plan 4 prerequisites

**Files:** none (git only)

- [ ] **Step 1: Confirm working tree, branch, prerequisite slices**

Run:
```bash
git status --short
git branch --show-current
git log --oneline -5
```

Expected: clean tree. Current branch is `feature/character-collections-and-stats` (created when slice 1 began per spec §2.4). If the branch does not yet exist (slices 1+2 not landed), stop and surface this — slice 3 is sequenced after stats and titles.

- [ ] **Step 2: Confirm professions slice is the closest analog**

Run:
```bash
grep -n "syncProfessions" app/Blizzard/Jobs/SyncCharacterData.php
grep -n "professions_enabled" config/blizzard.php
ls app/Blizzard/DTO/CharacterProfession.php app/Blizzard/Mappers/CharacterProfessionMapper.php app/Models/CharacterProfession.php app/Http/Resources/ProfessionResource.php
```

Expected: all four files exist; `syncProfessions` is at ~line 344 of `SyncCharacterData.php`; `professions_enabled` flag is in `config/blizzard.php`. This slice mirrors that file set 1:1 with `Reputation` substituted for `Profession`.

---

## Task 2: Migration — `character_reputations` table + `reputations_synced_at` column

**Files:**
- Create: `database/migrations/2026_04_28_100001_create_character_reputations_table.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_04_28_100001_create_character_reputations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_reputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('faction_id');
            $table->string('faction_name', 150);
            $table->string('standing', 20); // hated|hostile|unfriendly|neutral|friendly|honored|revered|exalted
            $table->integer('value')->default(0);
            $table->integer('max')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'faction_id'], 'character_reputations_unique');
            $table->index('character_id');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('reputations_synced_at')->nullable()->after('raids_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('reputations_synced_at');
        });

        Schema::dropIfExists('character_reputations');
    }
};
```

- [ ] **Step 2: Run the migration and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_reputations'));"
php artisan tinker --execute "var_dump(in_array('reputations_synced_at', Schema::getColumnListing('characters')));"
```

Expected: both `var_dump` print `bool(true)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100001_create_character_reputations_table.php
git commit -m "Add character_reputations table and reputations_synced_at column"
```

---

## Task 3: Add `reputations` staleness threshold + sync feature flag

**Files:**
- Modify: `config/blizzard.php`

- [ ] **Step 1: Add the staleness key**

In `config/blizzard.php`, locate the `staleness.character` block and add `reputations` after `raids`:

```php
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
            'reputations' => (int) env('BLIZZARD_STALE_CHARACTER_REPUTATIONS', 21600),
        ],
```

`21600` = 6 hours, matching professions. Reputation values change slowly (most sessions add only a few thousand points to a 21k-cap standing); a 6-hour TTL keeps Blizzard load low.

- [ ] **Step 2: Add the per-slice feature flag**

In the same file, locate the `sync` block and add `reputations_enabled` after `raids_enabled`:

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'reputations_enabled' => (bool) env('BLIZZARD_SYNC_REPUTATIONS_ENABLED', false),
    ],
```

Default is **`false`** per spec §5: each new slice ships dark and is ramped via env.

- [ ] **Step 3: Verify config loads**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "echo (int) config('blizzard.staleness.character.reputations');"
php artisan tinker --execute "var_dump((bool) config('blizzard.sync.reputations_enabled'));"
```

Expected: prints `21600` and `bool(false)`.

- [ ] **Step 4: Commit**

```bash
git add config/blizzard.php
git commit -m "Add reputations staleness threshold and BLIZZARD_SYNC_REPUTATIONS_ENABLED flag (default false)"
```

---

## Task 4: Add `CharacterReputation` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterReputation.php`

- [ ] **Step 1: Write the DTO**

Create `app/Blizzard/DTO/CharacterReputation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterReputation
{
    public function __construct(
        public int $factionId,
        public string $factionName,
        public string $standing,
        public int $value,
        public int $max,
    ) {}
}
```

`standing` is one of: `hated`, `hostile`, `unfriendly`, `neutral`, `friendly`, `honored`, `revered`, `exalted` (lowercased in the mapper).

- [ ] **Step 2: Verify the class loads**

Run:
```bash
php -l app/Blizzard/DTO/CharacterReputation.php
php -r "require 'vendor/autoload.php'; \$r = new App\Blizzard\DTO\CharacterReputation(factionId: 1, factionName: 'x', standing: 'exalted', value: 21000, max: 21000); echo \$r->factionName === 'x' ? 'ok' : 'bad';"
```

Expected: `No syntax errors detected`, then `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterReputation.php
git commit -m "Add CharacterReputation DTO"
```

---

## Task 5: Add `CharacterReputation` Eloquent model

**Files:**
- Create: `app/Models/CharacterReputation.php`

- [ ] **Step 1: Write the model**

Create `app/Models/CharacterReputation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterReputation extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'faction_id',
        'faction_name',
        'standing',
        'value',
        'max',
    ];

    protected function casts(): array
    {
        return [
            'faction_id' => 'integer',
            'value' => 'integer',
            'max' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
```

- [ ] **Step 2: Verify the class loads**

Run:
```bash
php -l app/Models/CharacterReputation.php
php artisan tinker --execute "echo class_exists('App\Models\CharacterReputation') ? 'ok' : 'bad';"
```

Expected: `No syntax errors detected`, then `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/CharacterReputation.php
git commit -m "Add CharacterReputation Eloquent model"
```

---

## Task 6: Extend `Character` model — relation, fillable, cast, staleness helper

**Files:**
- Modify: `app/Models/Character.php`

- [ ] **Step 1: Add `reputations_synced_at` to `$fillable`**

In `app/Models/Character.php`, locate the `$fillable` array. Replace the line:

```php
        'raids_synced_at',
    ];
```

with:

```php
        'raids_synced_at',
        'reputations_synced_at',
    ];
```

- [ ] **Step 2: Add the cast**

In the same file, locate the `casts()` method and add `'reputations_synced_at' => 'datetime',` after the `'raids_synced_at'` cast:

```php
            'raids_synced_at' => 'datetime',
            'reputations_synced_at' => 'datetime',
            'last_searched_at' => 'datetime',
```

- [ ] **Step 3: Add the `reputations()` HasMany relation**

In the same file, locate the `raidEncounterKills()` relation method and add a new method immediately after it:

```php
    public function reputations(): HasMany
    {
        return $this->hasMany(CharacterReputation::class);
    }
```

- [ ] **Step 4: Add the `isReputationsStale()` helper**

In the same file, locate the `isRaidsStale()` method and add a new method immediately after it:

```php
    public function isReputationsStale(): bool
    {
        return ! $this->reputations_synced_at
            || $this->reputations_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.reputations');
    }
```

- [ ] **Step 5: Verify**

Run:
```bash
php -l app/Models/Character.php
php artisan tinker --execute "\$c = App\Models\Character::factory()->make(); echo \$c->reputations()::class;"
```

Expected: `No syntax errors detected`, then `Illuminate\Database\Eloquent\Relations\HasMany`.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Character.php
git commit -m "Wire reputations relation, fillable, cast, and isReputationsStale on Character"
```

---

## Task 7: Add `CharacterReputationMapper`

**Files:**
- Create: `app/Blizzard/Mappers/CharacterReputationMapper.php`
- Create: `tests/Unit/Blizzard/Mappers/CharacterReputationMapperTest.php`

The Blizzard `/profile/wow/character/{realm}/{name}/reputations` response shape (per the public Blizzard API docs):

```json
{
  "character": { ... },
  "reputations": [
    {
      "faction": { "id": 2510, "name": "Valdrakken Accord" },
      "standing": {
        "raw": 21000,
        "value": 0,
        "max": 21000,
        "tier": 7,
        "name": "Exalted"
      }
    },
    ...
  ]
}
```

The mapper must:
- Lowercase `standing.name` (so we get `exalted`, not `Exalted`).
- Use `standing.raw` for `value` (cumulative rep total) — `value` in Blizzard's payload is rep within current standing, which is misleading without `tier`. Storing `raw` keeps the data lossless and simple to render.
- Use `standing.max` for `max`.
- Skip entries missing `faction.id`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Blizzard/Mappers/CharacterReputationMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterReputationMapper;
use Tests\TestCase;

class CharacterReputationMapperTest extends TestCase
{
    private function blizzardPayload(): array
    {
        return [
            'character' => ['name' => 'Cirna'],
            'reputations' => [
                [
                    'faction' => ['id' => 2510, 'name' => 'Valdrakken Accord'],
                    'standing' => [
                        'raw' => 38000,
                        'value' => 0,
                        'max' => 21000,
                        'tier' => 7,
                        'name' => 'Exalted',
                    ],
                ],
                [
                    'faction' => ['id' => 2511, 'name' => 'Iskaara Tuskarr'],
                    'standing' => [
                        'raw' => 9500,
                        'value' => 3500,
                        'max' => 12000,
                        'tier' => 5,
                        'name' => 'Honored',
                    ],
                ],
                // Edge: missing faction id — should be skipped.
                [
                    'faction' => ['name' => 'Mystery Faction'],
                    'standing' => ['raw' => 0, 'max' => 3000, 'name' => 'Neutral'],
                ],
            ],
        ];
    }

    public function test_maps_each_faction_to_dto(): void
    {
        $dtos = (new CharacterReputationMapper)->map($this->blizzardPayload());

        $this->assertCount(2, $dtos);

        $this->assertSame(2510, $dtos[0]->factionId);
        $this->assertSame('Valdrakken Accord', $dtos[0]->factionName);
        $this->assertSame('exalted', $dtos[0]->standing);
        $this->assertSame(38000, $dtos[0]->value);
        $this->assertSame(21000, $dtos[0]->max);

        $this->assertSame(2511, $dtos[1]->factionId);
        $this->assertSame('honored', $dtos[1]->standing);
        $this->assertSame(9500, $dtos[1]->value);
        $this->assertSame(12000, $dtos[1]->max);
    }

    public function test_returns_empty_array_for_null_input(): void
    {
        $this->assertSame([], (new CharacterReputationMapper)->map(null));
    }

    public function test_returns_empty_array_for_payload_without_reputations_key(): void
    {
        $this->assertSame([], (new CharacterReputationMapper)->map(['character' => []]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterReputationMapperTest.php
```

Expected: FAIL with `Class "App\Blizzard\Mappers\CharacterReputationMapper" not found`.

- [ ] **Step 3: Write the mapper**

Create `app/Blizzard/Mappers/CharacterReputationMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterReputation;

class CharacterReputationMapper
{
    /**
     * @return CharacterReputation[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];

        foreach ($data['reputations'] ?? [] as $entry) {
            $factionId = (int) ($entry['faction']['id'] ?? 0);
            if ($factionId === 0) {
                continue;
            }

            $out[] = new CharacterReputation(
                factionId: $factionId,
                factionName: (string) ($entry['faction']['name'] ?? 'Unknown'),
                standing: strtolower((string) ($entry['standing']['name'] ?? 'neutral')),
                value: (int) ($entry['standing']['raw'] ?? 0),
                max: (int) ($entry['standing']['max'] ?? 0),
            );
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterReputationMapperTest.php
```

Expected: PASS (3 tests, no failures).

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Mappers/CharacterReputationMapper.php tests/Unit/Blizzard/Mappers/CharacterReputationMapperTest.php
git commit -m "Add CharacterReputationMapper with faction id/name/standing/value/max"
```

---

## Task 8: Add `getCharacterReputations` to `BlizzardProfileClient`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardProfileClient.php`
- Modify: `tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`, add this method at the end of the class (before the final `}`):

```php
    public function test_get_character_reputations_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/reputations' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterReputations('the-maelstrom', 'cirna'));
    }

    public function test_get_character_reputations_returns_payload_on_200(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/reputations' => Http::response([
                'reputations' => [
                    ['faction' => ['id' => 2510, 'name' => 'Valdrakken Accord'],
                     'standing' => ['raw' => 21000, 'max' => 21000, 'name' => 'Exalted']],
                ],
            ], 200),
        ]);

        $payload = $this->makeClient('eu')->getCharacterReputations('The Maelstrom', 'Cirna');

        $this->assertIsArray($payload);
        $this->assertSame(2510, $payload['reputations'][0]['faction']['id']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:
```bash
./vendor/bin/phpunit --filter=test_get_character_reputations tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php
```

Expected: FAIL with `Method getCharacterReputations does not exist`.

- [ ] **Step 3: Add the client method**

In `app/Blizzard/Client/BlizzardProfileClient.php`, add this method immediately after the existing `getCharacterRaidEncounters()` method (around line 220):

```php
    public function getCharacterReputations(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/reputations");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run:
```bash
./vendor/bin/phpunit --filter=test_get_character_reputations tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Client/BlizzardProfileClient.php tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php
git commit -m "Add BlizzardProfileClient::getCharacterReputations"
```

---

## Task 9: Add `syncReputations` to `SyncCharacterData`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

This step mirrors `syncProfessions` exactly: feature-flag guard, delete-missing inside a `DB::transaction`, success-only `*_synced_at` update, slice-local `try/catch` so failures here cannot abort sibling slices.

- [ ] **Step 1: Add the use statements**

At the top of `app/Blizzard/Jobs/SyncCharacterData.php`, in the `use` block for app classes, add (alphabetically next to existing entries):

```php
use App\Blizzard\Mappers\CharacterReputationMapper;
```

and:

```php
use App\Models\CharacterReputation;
```

- [ ] **Step 2: Add the mapper to `handle()` method signature**

In `handle()`, locate the parameter list and add `CharacterReputationMapper $reputationMapper,` immediately after `RaidEncounterKillMapper $raidMapper,`:

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
        CharacterReputationMapper $reputationMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

- [ ] **Step 3: Dispatch the slice from the Full-depth block**

In `handle()`, locate the Full-depth block:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
        }
```

Replace it with:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncReputations($client, $reputationMapper, $character);
        }
```

- [ ] **Step 4: Add the `syncReputations()` method**

In the same file, immediately after `syncRaidEncounters()` (around line 438) and before `failed()`, insert:

```php
    private function syncReputations(
        BlizzardProfileClient $client,
        CharacterReputationMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.reputations_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterReputations($this->realm, $this->name);
            $dtos = $mapper->map($data);

            DB::transaction(function () use ($character, $dtos) {
                $keep = [];
                foreach ($dtos as $dto) {
                    CharacterReputation::updateOrCreate(
                        [
                            'character_id' => $character->id,
                            'faction_id' => $dto->factionId,
                        ],
                        [
                            'faction_name' => $dto->factionName,
                            'standing' => $dto->standing,
                            'value' => $dto->value,
                            'max' => $dto->max,
                        ],
                    );
                    $keep[] = $dto->factionId;
                }

                CharacterReputation::where('character_id', $character->id)
                    ->when($keep !== [], fn ($q) => $q->whereNotIn('faction_id', $keep))
                    ->delete();

                $character->update(['reputations_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Failed to sync reputations for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 5: Verify no syntax errors and the file class-loads**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
php artisan config:clear
composer test -- --filter=SyncCharacterData
```

Expected: `No syntax errors detected`. The existing `SyncCharacterDataNotFoundTest` continues to pass — adding a new optional slice gated on a flag (default `false`) cannot regress not-found behavior.

- [ ] **Step 6: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "Add syncReputations slice to SyncCharacterData (gated on BLIZZARD_SYNC_REPUTATIONS_ENABLED)"
```

---

## Task 10: Wire `isReputationsStale()` into `CharacterService`

**Files:**
- Modify: `app/Services/CharacterService.php`

- [ ] **Step 1: Extend the staleness OR-chain**

In `app/Services/CharacterService.php`, locate the `getByIdentity()` method and replace:

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
            || $character->isReputationsStale();
```

This means a character whose `reputations_synced_at` is null or older than threshold will trigger a `SyncDepth::Full` dispatch on next read.

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Services/CharacterService.php
composer test -- --filter=CharacterService
```

Expected: `No syntax errors detected`. Existing `CharacterServiceNotFoundTest` passes (extending the OR-chain doesn't change not-found behavior).

- [ ] **Step 3: Commit**

```bash
git add app/Services/CharacterService.php
git commit -m "Include reputations staleness in CharacterService::getByIdentity OR-chain"
```

---

## Task 11: Add `ReputationResource`

**Files:**
- Create: `app/Http/Resources/ReputationResource.php`

- [ ] **Step 1: Write the resource**

Create `app/Http/Resources/ReputationResource.php`:

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
        ];
    }
}
```

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Http/Resources/ReputationResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/ReputationResource.php
git commit -m "Add ReputationResource"
```

---

## Task 12: Expose `reputations` and `meta.freshness.reputations` in `CharacterResource`

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php`

- [ ] **Step 1: Add the `reputations` field to `toArray()`**

In `app/Http/Resources/CharacterResource.php`, locate:

```php
            'raid_progress' => RaidEncounterResource::collection($this->whenLoaded('raidEncounterKills')),
            'recruitment' => $this->recruitment,
```

Replace with:

```php
            'raid_progress' => RaidEncounterResource::collection($this->whenLoaded('raidEncounterKills')),
            'reputations' => ReputationResource::collection($this->whenLoaded('reputations')),
            'recruitment' => $this->recruitment,
```

- [ ] **Step 2: Add the freshness key**

In the same file, locate the `freshness` array in `with()`:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                ],
```

Replace with:

```php
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                    'reputations' => $this->freshnessFor('reputations_synced_at', 'reputations'),
                ],
```

- [ ] **Step 3: Verify**

Run:
```bash
php -l app/Http/Resources/CharacterResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Resources/CharacterResource.php
git commit -m "Expose reputations relation and meta.freshness.reputations in CharacterResource"
```

---

## Task 13: Eager-load `reputations` in `CharacterController::show`

**Files:**
- Modify: `app/Http/Controllers/CharacterController.php`

- [ ] **Step 1: Extend the eager-load list**

In `app/Http/Controllers/CharacterController.php` `show()`, locate:

```php
        $result->load(['guild', 'dungeonRuns.members', 'pvpBrackets', 'professions', 'raidEncounterKills']);
```

Replace with:

```php
        $result->load(['guild', 'dungeonRuns.members', 'pvpBrackets', 'professions', 'raidEncounterKills', 'reputations']);
```

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Http/Controllers/CharacterController.php
composer test
```

Expected: `No syntax errors detected`. Default test suite passes (no integration tests yet for reputations — added in Task 14).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/CharacterController.php
git commit -m "Eager-load reputations on character show"
```

---

## Task 14: Extend `RetailCharacterEndpointTest` with reputations-shape assertions

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`
- Modify: `tests/Feature/Endpoints/EndpointIntegrationTestCase.php`

- [ ] **Step 1: Add a `rep_grinder` fixture slot**

In `tests/Feature/Endpoints/EndpointIntegrationTestCase.php`, replace the `RETAIL_CHARACTERS` constant with:

```php
    public const RETAIL_CHARACTERS = [
        'geared_main'     => ['region' => 'eu', 'realm' => '', 'name' => ''], // sockets + enchants + tier set
        'pvp_player'      => ['region' => 'eu', 'realm' => '', 'name' => ''], // active PvP
        'profession_rich' => ['region' => 'eu', 'realm' => '', 'name' => ''], // 2 primaries + secondaries
        'raider'          => ['region' => 'eu', 'realm' => '', 'name' => ''], // active raider
        'rep_grinder'     => ['region' => 'eu', 'realm' => '', 'name' => ''], // many reputations across expansions
    ];
```

- [ ] **Step 2: Extend the JSON-structure + populated-data assertions**

In `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`, locate the `assertJsonStructure` call inside `test_retail_endpoint_returns_valid_response` and add `'reputations'` to the `data` keys list (after `'raid_progress'`) and `'reputations'` to the `meta.freshness` keys list:

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
                'reputations',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids', 'reputations'],
            ],
        ]);
```

Then immediately before the existing `$talents = $response->json('data.talents');` block, add:

```php
        $reputations = $response->json('data.reputations');
        $this->assertIsArray($reputations);

        if ($slot === 'rep_grinder') {
            $this->assertNotEmpty(
                $reputations,
                'rep_grinder fixture should expose at least one reputation entry; ' .
                'set BLIZZARD_SYNC_REPUTATIONS_ENABLED=true and re-run if empty.',
            );

            foreach ($reputations as $i => $rep) {
                $this->assertArrayHasKey('faction_id', $rep, "reputations[{$i}] missing faction_id");
                $this->assertArrayHasKey('faction_name', $rep, "reputations[{$i}] missing faction_name");
                $this->assertArrayHasKey('standing', $rep, "reputations[{$i}] missing standing");
                $this->assertArrayHasKey('value', $rep, "reputations[{$i}] missing value");
                $this->assertArrayHasKey('max', $rep, "reputations[{$i}] missing max");
                $this->assertContains(
                    $rep['standing'],
                    ['hated', 'hostile', 'unfriendly', 'neutral', 'friendly', 'honored', 'revered', 'exalted'],
                    "reputations[{$i}].standing has unexpected value '{$rep['standing']}'",
                );
            }
        }
```

The populated-data assertions are gated on `$slot === 'rep_grinder'` so the other four fixture slots (which may belong to characters with no/few rep grinds) don't fail when the array is short. The structure assertion still applies to all fixtures.

- [ ] **Step 3: Run integration tests (with credentials, fixture, flag flipped on)**

Only meaningful if `BLIZZARD_CLIENT_ID` / `BLIZZARD_CLIENT_SECRET` are set, the `rep_grinder` slot is filled, and `BLIZZARD_SYNC_REPUTATIONS_ENABLED=true` is exported for the run:

```bash
BLIZZARD_SYNC_REPUTATIONS_ENABLED=true composer test:integration
```

Expected: tests pass for filled fixtures, mark-skipped cleanly for empty slots.

- [ ] **Step 4: Run the default suite**

Run:
```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php tests/Feature/Endpoints/EndpointIntegrationTestCase.php
git commit -m "Extend RetailCharacterEndpointTest with reputations shape and rep_grinder fixture"
```

---

## Task 15: Add reputations support to `blizzard:backfill-slices`

**Files:**
- Modify: `app/Console/Commands/BlizzardBackfillSlicesCommand.php` (path is the canonical name; if the file lives elsewhere, find it first)

- [ ] **Step 1: Locate the backfill command**

Run:
```bash
grep -rln "blizzard:backfill-slices" app/Console/
```

If the file path differs from `app/Console/Commands/BlizzardBackfillSlicesCommand.php`, use whatever the grep returns for Step 2.

- [ ] **Step 2: Extend the null-check OR-chain**

In the backfill command, locate the slice null-check (it is currently an OR-chain over `mythics_synced_at`, `pvp_synced_at`, `professions_synced_at`, `raids_synced_at`). Add `reputations_synced_at` to the same OR-chain so a character with a null `reputations_synced_at` qualifies for backfill once the flag is ramped on. The exact code pattern is:

```php
->whereNull('mythics_synced_at')
->orWhereNull('pvp_synced_at')
->orWhereNull('professions_synced_at')
->orWhereNull('raids_synced_at')
->orWhereNull('reputations_synced_at')
```

Adapt to the existing query builder shape.

- [ ] **Step 3: Verify**

Run:
```bash
php artisan list blizzard
php artisan blizzard:backfill-slices --limit=0
```

Expected: command lists; the zero-limit dry run completes without errors.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/
git commit -m "Include reputations_synced_at in blizzard:backfill-slices null-check"
```

---

## Task 16: Run pint + update backend `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`
- Run: `./vendor/bin/pint`

- [ ] **Step 1: Run pint**

Run:
```bash
./vendor/bin/pint
git status --short
```

Expected: any pint-only whitespace changes show on files this slice touched.

- [ ] **Step 2: Commit pint fixes**

```bash
git add -A
git commit -m "Run pint on reputations slice changes" || echo "nothing to commit"
```

- [ ] **Step 3: Update `CLAUDE.md`**

In `CLAUDE.md`, under the `### Per-slice Full sync with feature flags` bullet, add a new bullet entry immediately after it:

```
- **Reputations slice.** Persists `(faction_id, faction_name, standing, value, max)` to `character_reputations` (delete-missing inside `DB::transaction`). `value` is `standing.raw` (lossless cumulative rep), `standing` is the lowercased name (`hated`..`exalted`). `BLIZZARD_SYNC_REPUTATIONS_ENABLED` defaults `false` — flip on to enable the slice without a code revert. Paragon counts and renown levels are deferred to a follow-up slice (require additional per-faction endpoint calls).
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "Document reputations slice in CLAUDE.md"
```

---

## Task 17: Frontend — extend `Reputation` type + meta.freshness.reputations

**Files:**
- Modify: `frontend/src/types/character.ts`

- [ ] **Step 1: Add the `Reputation` interface and standing literal**

In `frontend/src/types/character.ts`, immediately after the `Profession` interface (around line 86), add:

```typescript
export type ReputationStanding =
  | 'hated'
  | 'hostile'
  | 'unfriendly'
  | 'neutral'
  | 'friendly'
  | 'honored'
  | 'revered'
  | 'exalted'

export interface Reputation {
  faction_id: number
  faction_name: string
  standing: ReputationStanding
  value: number
  max: number
}
```

- [ ] **Step 2: Add `reputations` to `CharacterResource`**

In the same file, in the `CharacterResource` interface, replace:

```typescript
  raid_progress: RaidEncounterProgress[] | null
  recruitment: boolean
```

with:

```typescript
  raid_progress: RaidEncounterProgress[] | null
  reputations: Reputation[] | null
  recruitment: boolean
```

- [ ] **Step 3: Add `reputations` to `MetaBlock.freshness`**

In the same file, in the `MetaBlock` interface, replace:

```typescript
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
  }
```

with:

```typescript
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
    reputations: FreshnessState
  }
```

- [ ] **Step 4: Verify the type-check passes**

Run (from the `frontend/` directory):
```bash
cd ../frontend
npx vue-tsc --noEmit
cd -
```

Expected: zero errors. (The new fields are additive; existing components don't reference `reputations` yet, so nothing breaks.)

- [ ] **Step 5: Commit**

```bash
git -C ../frontend add src/types/character.ts
git -C ../frontend commit -m "Add Reputation type and meta.freshness.reputations to CharacterResource"
```

(Path-relative `git -C ../frontend` because backend and frontend are sibling dirs in this repo layout.)

---

## Task 18: Frontend — `ReputationsList` component

**Files:**
- Create: `frontend/src/components/character/ReputationsList.vue`

The component renders the flat reputation list grouped client-side by an inferred expansion bucket (Dragonflight = Valdrakken Accord, Iskaara Tuskarr, Maruuk Centaur, Loamm Niffen, Dream Wardens; The War Within = Council of Dornogal, The Assembly of the Deeps, Hallowfall Arathi, The Severed Threads; older expansions all bucketed as "Legacy" for v1). Faction-id → expansion mapping lives in this component for now (TODO comment links it to a follow-up that lifts it to a shared `wow.ts` constants module). Each row is a name + colored standing badge + `value/max` progress bar.

- [ ] **Step 1: Write the component**

Create `frontend/src/components/character/ReputationsList.vue`:

```vue
<template>
  <div class="flex flex-col gap-4">
    <div v-if="!entries || entries.length === 0" class="text-ma-muted/70 text-sm">
      No reputations recorded.
    </div>

    <div v-else v-for="group in groupedByExpansion" :key="group.label" class="card bg-base-200">
      <div class="card-body p-4">
        <h3 class="card-title text-base">{{ group.label }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <div
            v-for="rep in group.entries"
            :key="rep.faction_id"
            class="flex flex-col gap-1 border-l-2 pl-2"
            :class="standingBorderClass(rep.standing)"
          >
            <div class="flex items-center justify-between text-sm">
              <span>{{ rep.faction_name }}</span>
              <span class="badge badge-sm" :class="standingBadgeClass(rep.standing)">
                {{ rep.standing }}
              </span>
            </div>
            <progress
              class="progress h-1.5"
              :class="standingProgressClass(rep.standing)"
              :value="rep.max > 0 ? rep.value % rep.max : 0"
              :max="rep.max || 1"
            />
            <span class="text-[10px] text-ma-muted/60 tabular-nums">
              {{ rep.value.toLocaleString() }} / {{ rep.max.toLocaleString() }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

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

// TODO: lift to shared wow.ts constants once collections / achievements slices land.
const EXPANSION_BY_FACTION_ID: Record<number, { label: string; order: number }> = {
  // The War Within
  2570: { label: 'The War Within', order: 1 }, // Council of Dornogal
  2574: { label: 'The War Within', order: 1 }, // The Assembly of the Deeps
  2570 + 1: { label: 'The War Within', order: 1 }, // placeholder for additional TWW factions; trim after fixtures verified
  // Dragonflight
  2510: { label: 'Dragonflight', order: 2 }, // Valdrakken Accord
  2511: { label: 'Dragonflight', order: 2 }, // Iskaara Tuskarr
  2503: { label: 'Dragonflight', order: 2 }, // Maruuk Centaur
  2507: { label: 'Dragonflight', order: 2 }, // Dragonscale Expedition
  2564: { label: 'Dragonflight', order: 2 }, // Loamm Niffen
  2553: { label: 'Dragonflight', order: 2 }, // Soridormi
  2544: { label: 'Dragonflight', order: 2 }, // Artisan's Consortium
}

function bucketOf(rep: Reputation): { label: string; order: number } {
  return EXPANSION_BY_FACTION_ID[rep.faction_id] ?? { label: 'Legacy', order: 99 }
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
    .map((g) => ({ ...g, entries: [...g.entries].sort((a, b) => a.faction_name.localeCompare(b.faction_name)) }))
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

Mirrors `RaidEncountersList.vue` patterns: `defineProps` shape, `computed` group reduce, border + class maps. Uses DaisyUI primitives (`badge`, `progress`, `card`) per frontend/CLAUDE.md.

- [ ] **Step 2: Verify type-check**

Run (from `frontend/`):
```bash
cd ../frontend
npx vue-tsc --noEmit
cd -
```

Expected: zero errors.

- [ ] **Step 3: Commit**

```bash
git -C ../frontend add src/components/character/ReputationsList.vue
git -C ../frontend commit -m "Add ReputationsList component with expansion grouping and standing badges"
```

---

## Task 19: Frontend — wire `ReputationsList` into `CharacterReputationsTab`

**Files:**
- Modify: `frontend/src/pages/character/CharacterReputationsTab.vue`

- [ ] **Step 1: Replace `EmptyTab` stub with the populated component**

Replace the entire contents of `frontend/src/pages/character/CharacterReputationsTab.vue` with:

```vue
<template>
  <ReputationsList :entries="character?.reputations ?? null" />
  <EmptyTab
    v-if="!character?.reputations || character.reputations.length === 0"
    slice="reputations"
    :freshness="freshness"
    title="No reputations yet"
    :icon="Star"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Star } from 'lucide-vue-next'
import EmptyTab from '@/components/character/EmptyTab.vue'
import ReputationsList from '@/components/character/ReputationsList.vue'
import { useCharacterContext } from '@/composables/useCharacterContext'

const { character, meta } = useCharacterContext()

const freshness = computed(() => meta.value?.freshness?.reputations)
</script>
```

This pattern (list + EmptyTab fallback driven by freshness) matches how raid/profession tabs handle the "feature-flagged-off" + "freshly synced empty array" cases. When `BLIZZARD_SYNC_REPUTATIONS_ENABLED=false`, `reputations` will be an empty array (no eager-loaded relation rows) and `freshness === 'never_synced'`, which the EmptyTab spinner already handles.

- [ ] **Step 2: Verify the dev build**

Run (from `frontend/`):
```bash
cd ../frontend
npx vue-tsc --noEmit
npm run build
cd -
```

Expected: type-check passes, build succeeds. Vite `dist/` is regenerated (per frontend/CLAUDE.md `index.html` is served `no-store` so the deployment box gets the new bundle on next nginx hit).

- [ ] **Step 3: Commit**

```bash
git -C ../frontend add src/pages/character/CharacterReputationsTab.vue
git -C ../frontend commit -m "Render ReputationsList in CharacterReputationsTab with EmptyTab fallback"
```

---

## Task 20: Final verification

**Files:** none

- [ ] **Step 1: Run the full backend suite**

Run:
```bash
composer test
```

Expected: PASS, no regressions.

- [ ] **Step 2: Run the frontend type-check + build**

Run:
```bash
cd ../frontend
npx vue-tsc --noEmit
npm run build
cd -
```

Expected: zero TS errors, build succeeds.

- [ ] **Step 3: Manual smoke test (optional, requires a real character + flag flipped on)**

Run from the `backend/` directory:
```bash
docker compose up -d
docker compose exec -e BLIZZARD_SYNC_REPUTATIONS_ENABLED=true app php artisan blizzard:backfill-slices --limit=1
docker compose restart horizon
```

Then GET `/api/v1/characters/eu/<realm>/<name>` twice (first call may 202; second returns 200 with `data.reputations` populated assuming the character has rep grinds). The FE at `/characters/eu/<realm>/<name>/reputations` should render the faction list grouped by expansion.

- [ ] **Step 4: Confirm git log**

Run:
```bash
git log --oneline | head -25
git -C ../frontend log --oneline | head -10
```

Expected: ~16 backend commits + 3 frontend commits all named `<verb> ... reputations ...`. Branch is ready to push and PR.

---

## Done criteria for the reputations slice

- [ ] `character_reputations` table exists with `(character_id, faction_id)` unique index.
- [ ] `reputations_synced_at` column added to `characters`.
- [ ] `BLIZZARD_SYNC_REPUTATIONS_ENABLED` env var (default `false`) gates `syncReputations()`.
- [ ] `BLIZZARD_STALE_CHARACTER_REPUTATIONS` env var (default `21600`) drives `isReputationsStale()`.
- [ ] `CharacterReputation` DTO + Mapper + Eloquent model + `ReputationResource` exist.
- [ ] `BlizzardProfileClient::getCharacterReputations` returns `?array` (null on 404).
- [ ] `SyncCharacterData::syncReputations()` exists, mirrors `syncProfessions()` exactly (delete-missing inside `DB::transaction`, success-only `*_synced_at` update, slice-local `try/catch`).
- [ ] `Character::reputations()`, `Character::isReputationsStale()` wired in.
- [ ] `CharacterService::getByIdentity()` OR-chain includes `isReputationsStale()`.
- [ ] `CharacterController::show()` eager-loads `reputations`.
- [ ] `CharacterResource` exposes `data.reputations` and `meta.freshness.reputations`.
- [ ] `RetailCharacterEndpointTest` asserts the new shape; `rep_grinder` fixture slot exists.
- [ ] FE `Reputation` + `ReputationStanding` types defined; `CharacterResource.reputations`, `MetaBlock.freshness.reputations` updated.
- [ ] `ReputationsList.vue` component exists; renders flat list grouped by expansion client-side.
- [ ] `CharacterReputationsTab.vue` renders `ReputationsList` with `EmptyTab` fallback.
- [ ] `composer test` green; FE `vue-tsc` clean; FE `npm run build` succeeds.
- [ ] Backend `CLAUDE.md` documents the slice, the `value=standing.raw` decision, and the deferred paragon/renown work.
- [ ] `blizzard:backfill-slices` null-check covers `reputations_synced_at`.

---

## Deferred to follow-up slice

Per spec §2.2, the following are explicitly **out of scope** for this slice and will be tackled in a follow-up reputations slice once the basic table renders and concrete parity gaps are identified:

1. **Paragon counts.** Shadowlands+ factions can earn paragon caches at every 10k rep beyond exalted. Persisting these requires either a separate `/character/{...}/reputations` flag (which Blizzard does not expose) or a per-faction endpoint call — rate-limit cost is non-trivial. Defer until masked-armory parity demands it.
2. **Renown levels.** Dragonflight major factions (Valdrakken Accord, Iskaara Tuskarr, etc.) and War Within factions track renown separately from rep value. The renown level is on a sibling endpoint (`/profile/wow/character/{realm}/{name}/reputations` exposes `standing.value` for old factions and `standing.tier` for renown factions, but the labeled "Renown 25" scaling needs a per-faction game-data crosswalk). Defer until v1 parity highlights it as load-bearing.
3. **Faction icons.** Wowhead crosswalk for faction-id → icon URL is feasible but not required for the v1 list view. Defer until visual parity demands it.
4. **Classic-era reputations.** Classic persistence is owned by Plan 3 (game-version gating). Reputations data shape is similar enough that a follow-up can add a `game_version='classic'` branch when Plan 3 lands.
5. **Lifting expansion-by-faction-id mapping to a shared `wow.ts` constants module.** The collections and achievements slices will both need this; it makes sense to lift it once at least two consumers exist. The `TODO` comment in `ReputationsList.vue` flags this.

## Verification commands (quick reference)

```bash
# Backend
composer test                                # default suite
composer test:integration                    # e2e (needs creds + fixtures)
./vendor/bin/pint --test                     # style check
php artisan migrate:status | tail -5         # confirm migration applied

# Frontend
cd ../frontend
npx vue-tsc --noEmit                         # type-check
npm run build                                # full build (includes vue-tsc)
```
