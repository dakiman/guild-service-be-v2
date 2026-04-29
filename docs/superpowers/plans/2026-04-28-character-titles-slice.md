# Character Titles Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md` (slice 2 in §3 — titles).

**Goal:** Ship `/profile/wow/character/{realm}/{name}/titles` end-to-end so `CharacterTitlesTab.vue` renders the character's earned-title list with the active title highlighted, gated behind a default-off feature flag.

**Architecture:** Strictly additive, follows the Plan-2 per-slice pattern (see `app/Blizzard/Jobs/SyncCharacterData.php::syncProfessions/syncPvpData`). New `character_titles` table + `titles_synced_at` column on `characters`. New DTO + mapper + client method + sync method. `CharacterTitle` Eloquent model with `(character_id, title_id)` unique. `SyncCharacterData::syncTitles()` upserts rows then deletes rows missing from the latest response, all in one DB transaction. Slice is gated on `BLIZZARD_SYNC_TITLES_ENABLED` (default **false** per spec §5 — slices ramp independently). Frontend's `CharacterTitlesTab.vue` switches from `EmptyTab` to a real titles list; the `EmptyTab`'s `freshness="never_synced"` state still renders correctly while the flag is off.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Vue 3 + TS + DaisyUI.

**Persistence shape decision (beyond spec):** Each row stores `title_id` (Blizzard ID), `name`, `display_string`, `is_selected`. The directive originally specified `display_string_male` + `display_string_female`; **this plan persists a single `display_string` column** because the character `/titles` endpoint does not reliably return gender-specific strings — those live on the per-title game-data endpoint `/data/wow/title/{id}`. Resolving 50+ titles per sync would balloon the rate-limit budget. If gender-specific strings become a real requirement, add a separate game-data refresher slice (same pattern as the deferred `tier_name` resolution for PvP brackets — see CLAUDE.md "PvP bracket slugs are dynamic"). The FE renders whichever `display_string` Blizzard returned for the synced character.

**Out of scope (deferred):** Male/female title variants (separate game-data slice). Title-earned timestamps (Blizzard does not return them on this endpoint). Localized titles (current sync uses `locale=en_GB`, matching all other slices).

**Sequencing:** Slice 2 in spec §3. Single PR. Ships after the stats slice (slice 1) so the `display_string` JSONB pattern doesn't conflict — slices have no shared schema, so they can also land in either order.

**Deploy-ready at the end of:** this plan, with `BLIZZARD_SYNC_TITLES_ENABLED=false` everywhere. Flip to `true` per environment after smoke-testing in staging.

---

## Task 1: Create the feature branch

**Files:** none (git only)

- [ ] **Step 1: Verify working tree, branch from current trunk**

Run:
```bash
git status --short
git checkout -b feature/character-titles-slice
```

Expected: a new branch is created. The base branch is whatever `master` (or `feature/character-collections-and-stats` per spec §2.4 if the team adopted the umbrella branch) currently sits on.

- [ ] **Step 2: Confirm starting point**

Run:
```bash
git log --oneline -5
```

Expected: most recent commits include the Plan 4 spec (`docs/superpowers/specs/2026-04-28-character-collections-and-stats-design.md`).

---

## Task 2: Migration — create `character_titles` table + `titles_synced_at` column

**Files:**
- Create: `database/migrations/2026_04_28_100001_create_character_titles_table.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_04_28_100001_create_character_titles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('title_id');
            $table->string('name', 150);
            $table->string('display_string', 255);
            $table->boolean('is_selected')->default(false);
            $table->timestamps();

            $table->unique(['character_id', 'title_id'], 'character_titles_unique');
            $table->index('character_id');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('titles_synced_at')->nullable()->after('raids_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('titles_synced_at');
        });

        Schema::dropIfExists('character_titles');
    }
};
```

- [ ] **Step 2: Run the migration and verify**

Run:
```bash
php artisan migrate
php artisan tinker --execute "var_dump(Schema::hasTable('character_titles'), in_array('titles_synced_at', Schema::getColumnListing('characters'), true));"
```

Expected: `bool(true)` printed twice — table exists and `titles_synced_at` is on the `characters` table.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_28_100001_create_character_titles_table.php
git commit -m "Add character_titles table and titles_synced_at column"
```

---

## Task 3: Extend `config/blizzard.php` with titles staleness + sync flag

**Files:**
- Modify: `config/blizzard.php`

- [ ] **Step 1: Add `titles` staleness threshold**

In `config/blizzard.php`, replace the `staleness.character` block (lines 33-40) with:

```php
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
            'titles' => (int) env('BLIZZARD_STALE_CHARACTER_TITLES', 21600),
        ],
```

Rationale for `21600` (6h) default: titles change rarely (player earns one occasionally), so a long threshold reduces unnecessary syncs. Matches the professions threshold in the same file.

- [ ] **Step 2: Add the per-slice sync flag, default false**

In the same file, replace the `sync` block (lines 68-73) with:

```php
    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'titles_enabled' => (bool) env('BLIZZARD_SYNC_TITLES_ENABLED', false),
    ],
```

`BLIZZARD_SYNC_TITLES_ENABLED` defaults to **false** per spec §5 ("Each slice's `BLIZZARD_SYNC_{SLICE}_ENABLED` flag defaults to `false` so slices can be ramped independently in production"). Toggle to `true` in `.env` for local dev when running the integration test.

- [ ] **Step 3: Verify config loads**

Run:
```bash
php artisan config:clear
php artisan tinker --execute "echo config('blizzard.staleness.character.titles').' '.var_export(config('blizzard.sync.titles_enabled'), true);"
```

Expected: `21600 false`.

- [ ] **Step 4: Commit**

```bash
git add config/blizzard.php
git commit -m "Add titles staleness threshold and BLIZZARD_SYNC_TITLES_ENABLED flag"
```

---

## Task 4: Create `CharacterTitle` DTO

**Files:**
- Create: `app/Blizzard/DTO/CharacterTitle.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterTitle
{
    public function __construct(
        public int $titleId,
        public string $name,
        public string $displayString,
        public bool $isSelected,
    ) {}
}
```

- [ ] **Step 2: Verify class loads**

Run:
```bash
php -l app/Blizzard/DTO/CharacterTitle.php
php -r "require 'vendor/autoload.php'; \$t = new App\Blizzard\DTO\CharacterTitle(titleId: 256, name: 'Loremaster', displayString: 'Loremaster %s', isSelected: true); echo \$t->isSelected ? 'ok' : 'bad';"
```

Expected: `No syntax errors detected` then `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/DTO/CharacterTitle.php
git commit -m "Add CharacterTitle DTO"
```

---

## Task 5: Create `CharacterTitleMapper`

**Files:**
- Create: `app/Blizzard/Mappers/CharacterTitleMapper.php`

- [ ] **Step 1: Write the mapper**

The Blizzard `/profile/wow/character/{realm}/{name}/titles` response shape is:

```json
{
  "active_title": { "id": 256, "name": "Loremaster", "display_string": "Loremaster %s" },
  "titles": [
    { "id": 71,  "name": "Champion of the Frozen Wastes" },
    { "id": 256, "name": "Loremaster", "display_string": "Loremaster %s" }
  ]
}
```

`titles[]` entries always include `id` and `name`. `display_string` is only on `active_title` reliably; for the rest of the titles the mapper falls back to `name` (Blizzard's default rendering — most titles' display string is just `"<Name> %s"` or `"%s <Name>"`, and `name` alone is acceptable until a game-data refresher slice fills the gap).

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\CharacterTitle;

class CharacterTitleMapper
{
    /**
     * @return CharacterTitle[]
     */
    public function map(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $activeId = isset($data['active_title']['id']) ? (int) $data['active_title']['id'] : null;
        $activeDisplay = isset($data['active_title']['display_string'])
            ? (string) $data['active_title']['display_string']
            : null;

        $out = [];

        foreach ($data['titles'] ?? [] as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            $displayString = (string) ($entry['display_string'] ?? $name);

            // The active title's display_string from active_title tends to be richer
            // than the per-entry one — prefer it when this row is the active title.
            if ($id === $activeId && $activeDisplay !== null) {
                $displayString = $activeDisplay;
            }

            $out[] = new CharacterTitle(
                titleId: $id,
                name: $name,
                displayString: $displayString,
                isSelected: $id === $activeId,
            );
        }

        return $out;
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Mappers/CharacterTitleMapper.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/Mappers/CharacterTitleMapper.php
git commit -m "Add CharacterTitleMapper with active-title resolution"
```

---

## Task 6: Unit test for `CharacterTitleMapper`

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/CharacterTitleMapperTest.php`

- [ ] **Step 1: Create the test directory if missing**

Run:
```bash
mkdir -p tests/Unit/Blizzard/Mappers
```

- [ ] **Step 2: Write the failing test**

Write `tests/Unit/Blizzard/Mappers/CharacterTitleMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterTitleMapper;
use PHPUnit\Framework\TestCase;

class CharacterTitleMapperTest extends TestCase
{
    private CharacterTitleMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new CharacterTitleMapper;
    }

    public function test_returns_empty_array_for_null_payload(): void
    {
        $this->assertSame([], $this->mapper->map(null));
    }

    public function test_returns_empty_array_for_empty_titles(): void
    {
        $this->assertSame([], $this->mapper->map(['titles' => []]));
    }

    public function test_maps_titles_and_marks_active_one(): void
    {
        $data = [
            'active_title' => ['id' => 256, 'name' => 'Loremaster', 'display_string' => 'Loremaster %s'],
            'titles' => [
                ['id' => 71, 'name' => 'Champion of the Frozen Wastes'],
                ['id' => 256, 'name' => 'Loremaster'],
            ],
        ];

        $result = $this->mapper->map($data);

        $this->assertCount(2, $result);

        $this->assertSame(71, $result[0]->titleId);
        $this->assertSame('Champion of the Frozen Wastes', $result[0]->name);
        $this->assertSame('Champion of the Frozen Wastes', $result[0]->displayString);
        $this->assertFalse($result[0]->isSelected);

        $this->assertSame(256, $result[1]->titleId);
        $this->assertSame('Loremaster', $result[1]->name);
        $this->assertSame('Loremaster %s', $result[1]->displayString);
        $this->assertTrue($result[1]->isSelected);
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $data = [
            'titles' => [
                ['id' => 0, 'name' => 'Bogus'],
                ['id' => 7, 'name' => 'Real'],
            ],
        ];

        $result = $this->mapper->map($data);
        $this->assertCount(1, $result);
        $this->assertSame(7, $result[0]->titleId);
    }

    public function test_no_active_title_means_none_selected(): void
    {
        $data = [
            'titles' => [
                ['id' => 71, 'name' => 'Champion'],
                ['id' => 256, 'name' => 'Loremaster'],
            ],
        ];

        $result = $this->mapper->map($data);
        $this->assertCount(2, $result);
        $this->assertFalse($result[0]->isSelected);
        $this->assertFalse($result[1]->isSelected);
    }

    public function test_uses_per_entry_display_string_when_available(): void
    {
        $data = [
            'titles' => [
                ['id' => 9, 'name' => 'The Patient', 'display_string' => '%s the Patient'],
            ],
        ];

        $result = $this->mapper->map($data);
        $this->assertSame('%s the Patient', $result[0]->displayString);
    }
}
```

- [ ] **Step 3: Run the test and verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterTitleMapperTest.php
```

Expected: 5 tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Blizzard/Mappers/CharacterTitleMapperTest.php
git commit -m "Test CharacterTitleMapper for null/empty/active/skip/display-string paths"
```

---

## Task 7: Create `CharacterTitle` Eloquent model

**Files:**
- Create: `app/Models/CharacterTitle.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterTitle extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'title_id',
        'name',
        'display_string',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'title_id' => 'integer',
            'is_selected' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
```

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Models/CharacterTitle.php
php artisan tinker --execute "echo class_exists(App\Models\CharacterTitle::class) ? 'ok' : 'bad';"
```

Expected: `No syntax errors detected` then `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/CharacterTitle.php
git commit -m "Add CharacterTitle Eloquent model"
```

---

## Task 8: Update `Character` model — relation, fillable, casts, staleness

**Files:**
- Modify: `app/Models/Character.php`

- [ ] **Step 1: Add `titles_synced_at` to `$fillable`**

In `app/Models/Character.php`, in the `$fillable` array (lines 19-49), find:

```php
        'raids_synced_at',
    ];
```

and replace with:

```php
        'raids_synced_at',
        'titles_synced_at',
    ];
```

- [ ] **Step 2: Add `titles_synced_at` to `casts()`**

In the same file, in `casts()` (lines 51-70), find:

```php
            'raids_synced_at' => 'datetime',
```

and replace with:

```php
            'raids_synced_at' => 'datetime',
            'titles_synced_at' => 'datetime',
```

- [ ] **Step 3: Add the `titles()` HasMany relationship**

After the existing `raidEncounterKills()` method (lines 99-102), insert:

```php
    public function titles(): HasMany
    {
        return $this->hasMany(CharacterTitle::class);
    }
```

- [ ] **Step 4: Add `isTitlesStale()` helper**

After the existing `isRaidsStale()` method (lines 164-168), insert:

```php
    public function isTitlesStale(): bool
    {
        return ! $this->titles_synced_at
            || $this->titles_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.titles');
    }
```

- [ ] **Step 5: Verify**

Run:
```bash
php -l app/Models/Character.php
php artisan tinker --execute "\$c = App\Models\Character::factory()->make(); echo \$c->isTitlesStale() ? 'stale-as-expected' : 'unexpected-fresh';"
```

Expected: `No syntax errors detected` then `stale-as-expected` (a fresh-from-factory model has no `titles_synced_at` so the helper must report stale).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Character.php
git commit -m "Wire titles relation, titles_synced_at fillable/cast, isTitlesStale helper"
```

---

## Task 9: Add `getCharacterTitles()` method on `BlizzardProfileClient`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardProfileClient.php`

- [ ] **Step 1: Add the method**

In `app/Blizzard/Client/BlizzardProfileClient.php`, locate the `getCharacterRaidEncounters()` method (lines 205-220). Immediately AFTER it (before `getGuildData()`), insert:

```php
    public function getCharacterTitles(string $realm, string $name): ?array
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $response = $this->request()
            ->get("/profile/wow/character/{$realm}/{$name}/titles");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }
```

This mirrors `getCharacterProfessions()` and `getCharacterRaidEncounters()` exactly — single endpoint, 404 returns null, throws on other failures.

- [ ] **Step 2: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Client/BlizzardProfileClient.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Blizzard/Client/BlizzardProfileClient.php
git commit -m "Add BlizzardProfileClient::getCharacterTitles"
```

---

## Task 10: Add `syncTitles()` slice to `SyncCharacterData`

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Add `CharacterTitleMapper` and `CharacterTitle` model imports + `handle()` injection**

In `app/Blizzard/Jobs/SyncCharacterData.php`, update the `use` block. After:

```php
use App\Blizzard\Mappers\RaidEncounterKillMapper;
```

insert:

```php
use App\Blizzard\Mappers\CharacterTitleMapper;
```

After:

```php
use App\Models\Character;
```

insert:

```php
use App\Models\CharacterTitle;
```

(Keep alphabetical ordering — adjust if Pint shifts things.)

In the `handle()` signature (lines 73-85), find:

```php
        RaidEncounterKillMapper $raidMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

and replace with:

```php
        RaidEncounterKillMapper $raidMapper,
        CharacterTitleMapper $titleMapper,
        BlizzardGameDataClient $gameDataClient,
    ): void {
```

- [ ] **Step 2: Call `syncTitles()` from the Full-depth block**

In `handle()`, find the Full-depth block (lines 205-211):

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
        }
```

and replace with:

```php
        // Full depth: also sync mythic+ data
        if ($this->depth === SyncDepth::Full) {
            $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
            $this->syncPvpData($client, $pvpMapper, $character);
            $this->syncProfessions($client, $professionMapper, $character);
            $this->syncRaidEncounters($client, $raidMapper, $character);
            $this->syncTitles($client, $titleMapper, $character);
        }
```

- [ ] **Step 3: Add the `syncTitles()` private method**

In the same file, immediately AFTER `syncRaidEncounters()` (lines 391-438) and BEFORE `failed()`, insert:

```php
    private function syncTitles(
        BlizzardProfileClient $client,
        CharacterTitleMapper $mapper,
        Character $character,
    ): void {
        if (! config('blizzard.sync.titles_enabled')) {
            return;
        }

        try {
            $data = $client->getCharacterTitles($this->realm, $this->name);
            $dtos = $mapper->map($data);

            DB::transaction(function () use ($character, $dtos) {
                $keep = [];
                foreach ($dtos as $dto) {
                    CharacterTitle::updateOrCreate(
                        [
                            'character_id' => $character->id,
                            'title_id' => $dto->titleId,
                        ],
                        [
                            'name' => $dto->name,
                            'display_string' => $dto->displayString,
                            'is_selected' => $dto->isSelected,
                        ],
                    );
                    $keep[] = $dto->titleId;
                }

                CharacterTitle::where('character_id', $character->id)
                    ->when($keep !== [], fn ($q) => $q->whereNotIn('title_id', $keep))
                    ->delete();

                $character->update(['titles_synced_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Failed to sync titles for character', [
                'character' => "{$this->name}-{$this->realm}-{$this->region}",
                'error' => $e->getMessage(),
            ]);
        }
    }
```

This matches `syncPvpData()`'s structure exactly: feature-flag guard at top, try/catch around a `DB::transaction`, upsert + delete-missing inside the transaction, `titles_synced_at` updated only on success. Note: the `when($keep !== [], ...)` clause means an empty response wipes the character's titles — required delete-missing semantics per the spec/CLAUDE.md.

- [ ] **Step 4: Verify no syntax errors**

Run:
```bash
php -l app/Blizzard/Jobs/SyncCharacterData.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "Add syncTitles slice with delete-missing semantics"
```

---

## Task 11: Wire titles staleness into `CharacterService`

**Files:**
- Modify: `app/Services/CharacterService.php`

- [ ] **Step 1: Add `isTitlesStale()` to the OR-chain**

In `app/Services/CharacterService.php`, find the `$anySliceStale` block (lines 31-34):

```php
        $anySliceStale = $character->isMythicsStale()
            || $character->isPvpStale()
            || $character->isProfessionsStale()
            || $character->isRaidsStale();
```

and replace with:

```php
        $anySliceStale = $character->isMythicsStale()
            || $character->isPvpStale()
            || $character->isProfessionsStale()
            || $character->isRaidsStale()
            || $character->isTitlesStale();
```

This is the single source of truth for "should we dispatch Full sync?" — adding `isTitlesStale()` ensures titles are refreshed in the same dispatch as the other Full slices, not as a separate job.

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Services/CharacterService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/CharacterService.php
git commit -m "Include isTitlesStale in CharacterService Full-sync OR-chain"
```

---

## Task 12: Create `CharacterTitleResource`

**Files:**
- Create: `app/Http/Resources/CharacterTitleResource.php`

- [ ] **Step 1: Write the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->title_id,
            'name' => $this->name,
            'display_string' => $this->display_string,
            'is_selected' => (bool) $this->is_selected,
        ];
    }
}
```

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Http/Resources/CharacterTitleResource.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/CharacterTitleResource.php
git commit -m "Add CharacterTitleResource"
```

---

## Task 13: Expose titles in `CharacterResource`

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php`

- [ ] **Step 1: Add `titles` to `toArray()`**

In `app/Http/Resources/CharacterResource.php`, find the line:

```php
            'raid_progress' => RaidEncounterResource::collection($this->whenLoaded('raidEncounterKills')),
```

and replace with:

```php
            'raid_progress' => RaidEncounterResource::collection($this->whenLoaded('raidEncounterKills')),
            'titles' => CharacterTitleResource::collection($this->whenLoaded('titles')),
```

- [ ] **Step 2: Add `titles` to `with()` freshness map**

Find:

```php
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                ],
```

and replace with:

```php
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                    'titles' => $this->freshnessFor('titles_synced_at', 'titles'),
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
git commit -m "Expose titles relation and meta.freshness.titles in CharacterResource"
```

---

## Task 14: Eager-load `titles` in `CharacterController::show`

**Files:**
- Modify: `app/Http/Controllers/CharacterController.php`

- [ ] **Step 1: Add `titles` to the eager-load list**

In `app/Http/Controllers/CharacterController.php`, find:

```php
        $result->load(['guild', 'dungeonRuns.members', 'pvpBrackets', 'professions', 'raidEncounterKills']);
```

and replace with:

```php
        $result->load(['guild', 'dungeonRuns.members', 'pvpBrackets', 'professions', 'raidEncounterKills', 'titles']);
```

Without this, `whenLoaded('titles')` in `CharacterResource` returns an unconditional `MissingValue` and the FE never sees the field, even when rows exist.

- [ ] **Step 2: Verify**

Run:
```bash
php -l app/Http/Controllers/CharacterController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/CharacterController.php
git commit -m "Eager-load titles relation in CharacterController::show"
```

---

## Task 15: Backfill artisan command awareness (verify, no edit if already covers nulls generically)

**Files:**
- Read: `app/Console/Commands/` (find the backfill-slices command)
- Modify (only if the command hardcodes per-slice null checks): the backfill command.

The CLAUDE.md note (line 38) says `blizzard:backfill-slices --limit=N` "dispatches Full for any retail character with any null `*_synced_at`". If the command iterates `*_synced_at` columns generically, no edit is needed — adding `titles_synced_at` to the schema is enough for the command to pick it up. If it lists columns by name, append `titles_synced_at`.

- [ ] **Step 1: Locate the command**

Run:
```bash
grep -rn "backfill-slices\|titles_synced_at\|mythics_synced_at" app/Console/Commands/ | head
```

- [ ] **Step 2: Read the command source and decide**

If the command's WHERE clause names columns explicitly (e.g. `whereNull('mythics_synced_at')->orWhereNull('pvp_synced_at')...`), add `titles_synced_at` to that chain. If it loops over a `['mythics_synced_at', 'pvp_synced_at', ...]` array constant, append `'titles_synced_at'`.

If the command does any of those, edit it now. If it uses a more generic approach (Schema introspection, or queries `Character` for `\!any-stale`), no change needed.

- [ ] **Step 3: Verify and commit (if edited)**

Run:
```bash
php artisan blizzard:backfill-slices --help
```

Expected: command runs without error.

If you edited the command:

```bash
git add app/Console/Commands/<file>.php
git commit -m "Include titles_synced_at in backfill-slices null check"
```

If no edit was needed, skip the commit.

---

## Task 16: Add `BLIZZARD_SYNC_TITLES_ENABLED` to `.env.example`

**Files:**
- Modify: `.env.example` (only if it exists)

- [ ] **Step 1: Check if `.env.example` exists**

Run:
```bash
ls -la .env.example 2>/dev/null && grep -n "BLIZZARD_SYNC" .env.example || echo "no env example with sync flags"
```

- [ ] **Step 2: If existing sync flags are listed, append the new flag**

If `.env.example` lists e.g. `BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED=true`, append:

```
BLIZZARD_SYNC_TITLES_ENABLED=false
```

Default `false` matches `config/blizzard.php`.

If the file doesn't list any `BLIZZARD_SYNC_*` flags, do not add a one-off — that would be inconsistent. Skip.

- [ ] **Step 3: Commit (if edited)**

```bash
git add .env.example
git commit -m "Document BLIZZARD_SYNC_TITLES_ENABLED in .env.example"
```

---

## Task 17: Extend `RetailCharacterEndpointTest` with titles assertions

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Add `titles` to the `assertJsonStructure` `data` block**

In `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`, find:

```php
                'pvp_brackets',
                'professions',
                'raid_progress',
            ],
```

and replace with:

```php
                'pvp_brackets',
                'professions',
                'raid_progress',
                'titles',
            ],
```

- [ ] **Step 2: Add `titles` to the freshness assertions**

Find:

```php
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids'],
```

and replace with:

```php
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids', 'titles'],
```

- [ ] **Step 3: Add a new test method that exercises populated titles**

After the existing `test_retail_endpoint_returns_valid_response()` method (closing at the line containing `}` after the dungeon-runs assertions), insert (before the `private function warmCharacterOrSkip()` helper):

```php
    /**
     * Titles only populates when BLIZZARD_SYNC_TITLES_ENABLED=true.
     * Skip cleanly when the flag is off so the test passes in default env.
     */
    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_includes_titles_when_flag_enabled(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        if (! config('blizzard.sync.titles_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_TITLES_ENABLED is false; populated-titles assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $titles = $response->json('data.titles');
        $this->assertIsArray($titles);
        // A leveled retail character almost certainly has at least one title (e.g. "the Argent Champion").
        // If a character genuinely has none, the assertion still holds because the array is empty + valid.
        foreach ($titles as $i => $title) {
            $this->assertArrayHasKey('id', $title, "titles[{$i}] missing id");
            $this->assertArrayHasKey('name', $title, "titles[{$i}] missing name");
            $this->assertArrayHasKey('display_string', $title, "titles[{$i}] missing display_string");
            $this->assertArrayHasKey('is_selected', $title, "titles[{$i}] missing is_selected");
            $this->assertIsInt($title['id']);
            $this->assertIsString($title['name']);
            $this->assertIsString($title['display_string']);
            $this->assertIsBool($title['is_selected']);
        }

        // At most one title should be selected at a time.
        $selectedCount = count(array_filter($titles, fn ($t) => $t['is_selected'] === true));
        $this->assertLessThanOrEqual(1, $selectedCount, 'At most one title can be is_selected=true');

        $this->assertSame('fresh', $response->json('meta.freshness.titles'), 'titles freshness should be fresh after warm sync');
    }
```

- [ ] **Step 4: Run the default suite (which excludes integration)**

Run:
```bash
composer test
```

Expected: default suite stays green. Integration tests are excluded from this run.

- [ ] **Step 5: Run the integration test (only if credentials + fixtures are set AND `BLIZZARD_SYNC_TITLES_ENABLED=true`)**

Run:
```bash
BLIZZARD_SYNC_TITLES_ENABLED=true composer test:integration -- --filter=titles
```

Expected: filled fixtures pass; empty fixtures skip cleanly. With the flag off, the new test marks-skipped — also acceptable.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "Assert titles structure and freshness in RetailCharacterEndpointTest"
```

---

## Task 18: FE — extend `CharacterResource` TypeScript type

**Files:**
- Modify: `frontend/src/types/character.ts`

The frontend lives in a separate Git checkout (`../guild-service-fe-v2` per backend CLAUDE.md, OR the sibling `frontend/` dir per repo layout). Run all frontend commands from the frontend directory.

- [ ] **Step 1: Add the `CharacterTitle` type**

In `frontend/src/types/character.ts`, near the other entity sub-types (e.g. above `CharacterResource`), add:

```ts
export interface CharacterTitle {
  id: number
  name: string
  display_string: string
  is_selected: boolean
}
```

- [ ] **Step 2: Add `titles` to `CharacterResource`**

Find:

```ts
  raid_progress: RaidEncounterProgress[] | null
  recruitment: boolean
```

and replace with:

```ts
  raid_progress: RaidEncounterProgress[] | null
  titles: CharacterTitle[]
  recruitment: boolean
```

`titles: CharacterTitle[]` (not nullable) because `whenLoaded('titles')` always returns an array (possibly empty) when the relation is loaded — and we eager-load it in Task 14.

- [ ] **Step 3: Add `titles` to `MetaBlock.freshness`**

Find:

```ts
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
  }
```

and replace with:

```ts
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
    titles: FreshnessState
  }
```

- [ ] **Step 4: Verify the type-checker is happy**

Run:
```bash
cd frontend && npx vue-tsc -b --noEmit
```

Expected: no TypeScript errors. (If errors complain about an unused `CharacterTitle` import — that's fine; Task 19 uses it.)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types/character.ts
git commit -m "Add CharacterTitle type and titles freshness to MetaBlock"
```

---

## Task 19: FE — replace `CharacterTitlesTab.vue` stub with the real component

**Files:**
- Modify: `frontend/src/pages/character/CharacterTitlesTab.vue`

- [ ] **Step 1: Replace the file**

Replace the entire contents of `frontend/src/pages/character/CharacterTitlesTab.vue` with:

```vue
<template>
  <div class="flex flex-col gap-4">
    <EmptyTab
      v-if="character.titles.length === 0"
      slice="titles"
      :freshness="freshness.titles"
      title="No titles yet"
      message="Titles will appear here once the next sync completes."
      :icon="Crown"
    />

    <div v-else class="ma-card p-6 flex flex-col gap-3">
      <div v-if="selectedTitle" class="flex items-center gap-2 text-ma-accent">
        <Crown class="w-5 h-5" />
        <span class="ma-text-heading text-lg">{{ selectedTitle.display_string }}</span>
        <span class="badge badge-primary badge-sm ml-auto">Equipped</span>
      </div>

      <div class="divider my-0" v-if="selectedTitle && otherTitles.length > 0" />

      <ul v-if="otherTitles.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <li
          v-for="title in otherTitles"
          :key="title.id"
          class="flex items-center gap-2 px-3 py-2 rounded bg-base-200/40 text-sm text-ma-muted"
        >
          {{ title.display_string }}
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Crown } from 'lucide-vue-next'
import { useCharacterContext } from '@/composables/useCharacterContext'
import EmptyTab from '@/components/character/EmptyTab.vue'

const { character, freshness } = useCharacterContext()

const selectedTitle = computed(() => character.value.titles.find((t) => t.is_selected) ?? null)

const otherTitles = computed(() =>
  [...character.value.titles]
    .filter((t) => !t.is_selected)
    .sort((a, b) => a.name.localeCompare(b.name)),
)
</script>
```

Notes on the component:
- Mirrors `CharacterTalentsTab.vue` pattern: pulls `character` and `freshness` from `useCharacterContext()`.
- Empty state still uses `<EmptyTab>` so the loading/never-synced spinner state from `EmptyTab.vue` keeps working when the flag is off (FE sees `freshness.titles === 'never_synced'`).
- Active title is highlighted at the top with a Crown icon and "Equipped" badge.
- Remaining titles are alphabetized by name (Blizzard returns in earned-order, which is rarely useful for a long list).
- Uses DaisyUI `badge`, `divider`, and Tailwind utility classes — no bespoke CSS (per frontend CLAUDE.md "use DaisyUI semantic classes...where a DaisyUI primitive fits").

- [ ] **Step 2: Build to verify type-correctness**

Run:
```bash
cd frontend && npm run build
```

Expected: `vue-tsc -b` passes; Vite build emits to `dist/`.

- [ ] **Step 3: Manual smoke test**

Run:
```bash
cd frontend && npm run dev
```

Open `http://localhost:5173/characters/eu/<realm>/<name>/titles` for a character that has been synced with `BLIZZARD_SYNC_TITLES_ENABLED=true`.

Expected: when `titles` array is populated, the active title appears at the top with the Crown icon, and the other titles render in a 2-column grid below. When titles is empty (default with flag off), `EmptyTab` displays the "No titles yet" placeholder.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/character/CharacterTitlesTab.vue
git commit -m "Render character titles list with active title highlighted"
```

---

## Task 20: Run pint, update CLAUDE.md, final verification

**Files:**
- Run: `./vendor/bin/pint`
- Modify: `CLAUDE.md` (backend)

- [ ] **Step 1: Run pint to normalize code style**

Run:
```bash
./vendor/bin/pint
git status --short
```

Expected: pint formats any files it wants. Verify only files this plan touched changed.

- [ ] **Step 2: Commit pint fixes (if any)**

```bash
git add -A
git diff --cached --stat
git commit -m "Run pint on titles slice changes" || echo "nothing to commit"
```

- [ ] **Step 3: Document the slice in `CLAUDE.md`**

Edit `CLAUDE.md`. In the "Per-slice Full sync with feature flags" bullet, find the existing list of slices:

```
- **Per-slice Full sync with feature flags.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs four independent slice writes (mythic+, pvp, professions, raids) after the Standard-depth writes.
```

and replace with:

```
- **Per-slice Full sync with feature flags.** `SyncCharacterData::handle()` on `SyncDepth::Full` runs five independent slice writes (mythic+, pvp, professions, raids, titles) after the Standard-depth writes.
```

In the "Delete-missing semantics" bullet, append `character_titles` to the list of upsert+delete tables:

Find:
```
- **Delete-missing semantics.** `character_pvp_brackets`, `character_professions`, `raid_encounter_kills` all upsert then delete rows not present in the latest response, inside the slice's `DB::transaction`.
```

Replace with:
```
- **Delete-missing semantics.** `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`, `character_titles` all upsert then delete rows not present in the latest response, inside the slice's `DB::transaction`.
```

After the existing slice-specific bullets and BEFORE the "ProactiveSyncCharacters tier 1" bullet, add:

```
- **Titles slice.** `character_titles` rows carry `(title_id, name, display_string, is_selected)` where `is_selected` flags the character's currently equipped title (zero or one row per character). Display string is whatever Blizzard returns on the character `/titles` endpoint — gender-specific variants live on the per-title game-data endpoint and are out of scope for this slice. `BLIZZARD_SYNC_TITLES_ENABLED` defaults to `false` (ramp manually per environment).
```

- [ ] **Step 4: Commit CLAUDE.md update**

```bash
git add CLAUDE.md
git commit -m "Document titles slice in CLAUDE.md"
```

- [ ] **Step 5: Final verification**

Run from the backend directory:
```bash
composer test
git log --oneline -25
php artisan migrate:status | tail -5
```

Expected:
- Default test suite passes (including the new `CharacterTitleMapperTest`).
- Git log shows ~18-20 small commits from the plan.
- The new migration is listed as `Ran`.

Run from the frontend directory:
```bash
cd frontend && npm run build
```

Expected: build succeeds with no `vue-tsc` errors.

- [ ] **Step 6: Branch is ready for PR**

Run:
```bash
git log --oneline master..HEAD | wc -l
git diff master...HEAD --stat
```

Expected: ~18-20 commits, scope limited to titles-related files.

---

## Done criteria for Titles Slice

- [ ] `character_titles` table exists with `(character_id, title_id)` unique index.
- [ ] `characters.titles_synced_at` column exists.
- [ ] `BLIZZARD_SYNC_TITLES_ENABLED` flag exists in `config/blizzard.php`, defaulting to `false`.
- [ ] `BLIZZARD_STALE_CHARACTER_TITLES` config-driven, defaulting to `21600` (6h).
- [ ] `CharacterTitle` DTO + `CharacterTitleMapper` exist; mapper unit-tested for null/empty/active/skip/per-entry-display-string paths.
- [ ] `CharacterTitle` Eloquent model exists with `(character_id, title_id, name, display_string, is_selected)` fillable.
- [ ] `Character::titles()` HasMany relation + `Character::isTitlesStale()` helper exist.
- [ ] `CharacterService::getByIdentity()` includes `isTitlesStale()` in the Full-sync OR-chain.
- [ ] `BlizzardProfileClient::getCharacterTitles()` exists and matches the `getCharacterProfessions()` shape.
- [ ] `SyncCharacterData::syncTitles()` exists with try/catch, transaction, upsert+delete-missing, `titles_synced_at` updated only on success.
- [ ] `CharacterTitleResource` + `CharacterResource` emit titles via `whenLoaded('titles')` and freshness via `meta.freshness.titles`.
- [ ] `CharacterController::show()` eager-loads `titles`.
- [ ] FE `CharacterResource` type has `titles: CharacterTitle[]` and `MetaBlock.freshness.titles`.
- [ ] FE `CharacterTitlesTab.vue` renders the active title prominently and other titles in a sorted grid; falls back to `EmptyTab` when the array is empty.
- [ ] `RetailCharacterEndpointTest` asserts the titles structure and freshness key (skipping when flag off).
- [ ] CLAUDE.md updated to mention five Full-sync slices, the new delete-missing table, and the titles slice's specifics.
- [ ] Branch `feature/character-titles-slice` ready for PR; default test suite green; FE build green.

---

## Verification commands (single block, copy/paste)

```bash
# Backend
cd backend
php artisan migrate:status | grep titles
composer test
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/CharacterTitleMapperTest.php
php artisan tinker --execute "var_dump(Schema::hasTable('character_titles'));"
php artisan tinker --execute "echo config('blizzard.staleness.character.titles').' '.var_export(config('blizzard.sync.titles_enabled'), true);"

# Frontend
cd ../frontend
npm run build

# Optional: integration test (requires Blizzard credentials, populated fixtures, and BLIZZARD_SYNC_TITLES_ENABLED=true)
cd ../backend
BLIZZARD_SYNC_TITLES_ENABLED=true composer test:integration
```
