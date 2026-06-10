# Plan 5 — Mounts Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` (sub-slice 3 in §2.7 — mounts).

**Goal:** Hydrate `CharacterMount` rows with full mount metadata (description, source text, summon spell ID, teaching item ID) from Blizzard's Game Data API. Lets `MountsSubtab.vue` render a source caption under each mount name and switch its Wowhead tooltip from the bare mount name to a proper `spell={summon_spell_id}` link, which renders the iconic mount tooltip from Wowhead's CDN.

**Architecture:** Same shape as the factions and titles slices established earlier on `feature/plan-5-game-data-resolver`: a single `game_data_mounts` reference table populated by a new Artisan-command arm (`blizzard:sync-game-data mounts`) that hits `/data/wow/mount/{index,id}` in the `static-{region}` namespace via `BlizzardGameDataClient::getMountIndex()` and `getMount()`. `CharacterMount` Eloquent model gains a `gameData()` `belongsTo` relation; `MountResource` adds a `game_data` block via `whenLoaded`. FE `Mount` type gains an optional `game_data` block; `MountsSubtab.vue` consumes `source_text` and `summon_spell_id` when present and falls back to current behavior otherwise. No feature flag — eager-load is unconditional, missing `game_data_mounts` rows render with the existing fallback (just the name, like today).

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Vue 3 + TS + DaisyUI.

**Out of scope (deferred or in other slices):** Pet and toy game-data resolution (spec §5 — out-of-scope; existing `creature_display_id` and `toy_id` columns are already sufficient for Wowhead linking). Faction, title, achievement work (their own Plan 5 sub-slices). Removal of Plan 4 `BLIZZARD_SYNC_*_ENABLED` flags (cleanup slice).

**Sequencing:** Sub-slice 3 of 5. Runs after the factions slice (foundation: Artisan command shape, eager-load wiring, `BLIZZARD_GAME_DATA_CACHE_TTL` config) and after the titles slice (Artisan-command extension precedent). Branch `feature/plan-5-game-data-resolver` already exists; this slice commits onto it.

**Deploy-ready at the end of:** this plan, after running `php artisan migrate && php artisan blizzard:sync-game-data mounts` in each environment. The first prod sync may take several minutes (~1.2k mount detail calls at Blizzard's 100 req/s budget = under a minute, but with retries and rate-limiter middleware variance, plan for a longer window).

---

## Task 1: Migration — `game_data_mounts` table

**Files:**
- Create: `database/migrations/2026_04_30_100004_create_game_data_mounts_table.php`

- [ ] **Step 1: Confirm you are on the feature branch**

Run:
```bash
cd backend
git status --short
git branch --show-current
```

Expected: working tree clean, branch `feature/plan-5-game-data-resolver`. (If not on the branch, `git checkout feature/plan-5-game-data-resolver`.)

- [ ] **Step 2: Write the migration**

Create `backend/database/migrations/2026_04_30_100004_create_game_data_mounts_table.php`:

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
        Schema::create('game_data_mounts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('source_text', 255)->nullable();
            $table->unsignedInteger('summon_spell_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->timestamps();

            $table->index('summon_spell_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_mounts');
    }
};
```

- [ ] **Step 3: Run the migration locally**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors. `game_data_mounts` table exists.

- [ ] **Step 4: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_mounts'));"
```

Expected: `["id", "name", "description", "source_text", "summon_spell_id", "item_id", "created_at", "updated_at"]`.

- [ ] **Step 5: Commit**

Run:
```bash
git add database/migrations/2026_04_30_100004_create_game_data_mounts_table.php
git commit -m "feat(plan-5): add game_data_mounts table"
```

---

## Task 2: Eloquent model — `GameDataMount`

**Files:**
- Create: `app/Models/GameDataMount.php`

- [ ] **Step 1: Write the model**

Create `backend/app/Models/GameDataMount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataMount extends Model
{
    protected $fillable = [
        'id',
        'name',
        'description',
        'source_text',
        'summon_spell_id',
        'item_id',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'summon_spell_id' => 'integer',
            'item_id' => 'integer',
        ];
    }
}
```

- [ ] **Step 2: Smoke-test the model in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataMount;
\$m = GameDataMount::create([
  'id' => 999999,
  'name' => 'Test Mount',
  'description' => 'A test mount.',
  'source_text' => 'Drop: Test Boss',
  'summon_spell_id' => 12345,
  'item_id' => 67890,
]);
dump(\$m->fresh()->toArray());
\$m->delete();
"
```

Expected: dump prints all fields with proper types (id as int, summon_spell_id as int, etc.), row cleans up.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Models/GameDataMount.php
git commit -m "feat(plan-5): add GameDataMount model"
```

---

## Task 3: DTO — `GameDataMount`

**Files:**
- Create: `app/Blizzard/DTO/GameDataMount.php`

- [ ] **Step 1: Write the DTO**

Create `backend/app/Blizzard/DTO/GameDataMount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataMount
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public ?string $sourceText,
        public ?int $summonSpellId,
        public ?int $itemId,
    ) {}
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/DTO/GameDataMount.php
git commit -m "feat(plan-5): add GameDataMount DTO"
```

---

## Task 4: Mapper — `GameDataMountMapper`

**Files:**
- Create: `app/Blizzard/Mappers/GameDataMountMapper.php`

- [ ] **Step 1: Write the mapper**

Create `backend/app/Blizzard/Mappers/GameDataMountMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataMount;

class GameDataMountMapper
{
    /**
     * Map a single Blizzard /data/wow/mount/{id} response to a GameDataMount DTO.
     *
     * Notable extractions:
     *  - `description` arrives as a plain string when locale is set in the
     *    request, but some legacy responses nest it as `description.en_GB`.
     *    Both shapes are tolerated.
     *  - `source` is an object `{ type: "DROP", name: "..." }` describing how
     *    the mount is acquired; we flatten to a single `source_text` like
     *    "Drop: Onyxia" using title-cased type + ": " + name.
     *  - `summon_spell` is the spell that summons the mount; its `id` is what
     *    powers Wowhead's `spell=` tooltip widget on the FE.
     *  - `item.id` (when present) is the in-game item that teaches the mount;
     *    useful for "Source: this item" rendering.
     */
    public function mapDetail(?array $data): ?GameDataMount
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return new GameDataMount(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Unknown'),
            description: $this->extractDescription($data),
            sourceText: $this->extractSourceText($data),
            summonSpellId: isset($data['summon_spell']['id'])
                ? (int) $data['summon_spell']['id']
                : null,
            itemId: isset($data['item']['id'])
                ? (int) $data['item']['id']
                : null,
        );
    }

    /**
     * Description may arrive as a plain string (typical when ?locale=en_GB
     * is set on the request), or — defensively — as a nested locale map.
     */
    private function extractDescription(array $data): ?string
    {
        if (! isset($data['description'])) {
            return null;
        }

        $d = $data['description'];

        if (is_string($d)) {
            return $d !== '' ? $d : null;
        }

        if (is_array($d) && isset($d['en_GB']) && is_string($d['en_GB'])) {
            return $d['en_GB'] !== '' ? $d['en_GB'] : null;
        }

        return null;
    }

    /**
     * Flatten { type: "DROP", name: "Onyxia" } to "Drop: Onyxia".
     * Returns null if either field is missing.
     */
    private function extractSourceText(array $data): ?string
    {
        $type = $data['source']['type'] ?? null;
        $name = $data['source']['name'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        if (! is_string($name) || $name === '') {
            // Type alone (e.g. "ACHIEVEMENT" with no specific name) — render the type only.
            return ucfirst(strtolower($type));
        }

        return ucfirst(strtolower($type)).': '.$name;
    }

    /**
     * Extract mount IDs from a /data/wow/mount/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['mounts'] ?? [] as $entry) {
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
git add app/Blizzard/Mappers/GameDataMountMapper.php
git commit -m "feat(plan-5): add GameDataMountMapper"
```

---

## Task 5: Mapper test — `GameDataMountMapperTest`

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataMountMapperTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataMountMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataMountMapper;
use PHPUnit\Framework\TestCase;

class GameDataMountMapperTest extends TestCase
{
    private GameDataMountMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataMountMapper();
    }

    public function test_maps_full_detail_response(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 6,
            'name' => 'Onyxian Drake',
            'description' => 'A drake born of Onyxia\'s brood.',
            'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
            'summon_spell' => ['id' => 69395, 'name' => 'Onyxian Drake'],
            'item' => ['id' => 49636, 'name' => 'Reins of the Onyxian Drake'],
        ]);

        $this->assertSame(6, $dto->id);
        $this->assertSame('Onyxian Drake', $dto->name);
        $this->assertSame("A drake born of Onyxia's brood.", $dto->description);
        $this->assertSame('Drop: Onyxia', $dto->sourceText);
        $this->assertSame(69395, $dto->summonSpellId);
        $this->assertSame(49636, $dto->itemId);
    }

    public function test_handles_nested_locale_description(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'description' => ['en_GB' => 'British description'],
        ]);

        $this->assertSame('British description', $dto->description);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Bare Mount',
        ]);

        $this->assertSame(1, $dto->id);
        $this->assertSame('Bare Mount', $dto->name);
        $this->assertNull($dto->description);
        $this->assertNull($dto->sourceText);
        $this->assertNull($dto->summonSpellId);
        $this->assertNull($dto->itemId);
    }

    public function test_source_with_type_only_is_title_cased_without_colon(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'source' => ['type' => 'ACHIEVEMENT'],
        ]);

        $this->assertSame('Achievement', $dto->sourceText);
    }

    public function test_source_with_quest_type_renders_correctly(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'source' => ['type' => 'QUEST', 'name' => 'A Mighty Steed'],
        ]);

        $this->assertSame('Quest: A Mighty Steed', $dto->sourceText);
    }

    public function test_empty_string_description_yields_null(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 1,
            'name' => 'Test',
            'description' => '',
        ]);

        $this->assertNull($dto->description);
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
            'mounts' => [
                ['id' => 6, 'name' => 'Onyxian Drake'],
                ['id' => 219, 'name' => 'Tawny Wind Rider'],
                ['name' => 'no-id'], // skipped
            ],
        ]);

        $this->assertSame([6, 219], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
```

- [ ] **Step 2: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataMountMapperTest.php
```

Expected: 10 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Mappers/GameDataMountMapperTest.php
git commit -m "test(plan-5): cover GameDataMountMapper with 10 cases"
```

---

## Task 6: Client methods — `BlizzardGameDataClient::getMountIndex()` and `getMount()`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Add the two methods**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php` and append the following methods inside the class (after the existing `getFaction()` / `getTitle()` methods that earlier Plan 5 sub-slices added):

```php
    /**
     * Fetch the mount index from /data/wow/mount/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * Lives in the static-{region} namespace (patch-pinned reference data),
     * not dynamic-, so we bypass request() and call Http directly — same
     * convention as getTalentTree() / getFactionIndex() above.
     *
     * Cached aggressively because the index only changes on patches.
     */
    public function getMountIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:mount-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mount/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single mount by ID from /data/wow/mount/{id}.
     * Returns the raw response array (description, source, summon_spell, item, etc.).
     *
     * Cached for the same TTL as the index.
     */
    public function getMount(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:mount:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/mount/{$id}");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }
```

(The `BLIZZARD_GAME_DATA_CACHE_TTL` config + env entry already landed with the factions slice — no config changes needed here.)

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/Client/BlizzardGameDataClient.php
git commit -m "feat(plan-5): add getMountIndex and getMount methods to BlizzardGameDataClient"
```

---

## Task 7: Client method tests — append to `BlizzardGameDataClientTest`

**Files:**
- Modify: `tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`

- [ ] **Step 1: Add `getMountIndex` tests**

Append the following methods inside the existing `BlizzardGameDataClientTest` class (which the factions slice created):

```php
    public function test_get_mount_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/mount/index?*' => Http::response([
                'mounts' => [
                    ['id' => 6, 'name' => 'Onyxian Drake'],
                    ['id' => 219, 'name' => 'Tawny Wind Rider'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getMountIndex();

        $this->assertSame(6, $result['mounts'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'mount/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_mount_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['mounts' => []], 200);
        });

        $client = $this->client();
        $client->getMountIndex();
        $client->getMountIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }
```

- [ ] **Step 2: Add `getMount` tests**

Append:

```php
    public function test_get_mount_returns_full_detail(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/mount/6?*' => Http::response([
                'id' => 6,
                'name' => 'Onyxian Drake',
                'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
                'summon_spell' => ['id' => 69395, 'name' => 'Onyxian Drake'],
                'item' => ['id' => 49636],
            ], 200),
        ]);

        $result = $this->client()->getMount(6);

        $this->assertSame(6, $result['id']);
        $this->assertSame('Onyxian Drake', $result['name']);
        $this->assertSame(69395, $result['summon_spell']['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'mount/6')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_mount_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/mount/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getMount(99999));
    }
```

- [ ] **Step 3: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
```

Expected: all tests pass — the 4 new ones plus all earlier ones (factions + titles + pre-existing talent tree / mythic season).

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
git commit -m "test(plan-5): cover getMountIndex and getMount client methods"
```

---

## Task 8: Extend `SyncGameData` Artisan command with `mounts` arm

**Files:**
- Modify: `app/Console/Commands/SyncGameData.php`

- [ ] **Step 1: Update imports and the `handle()` `match` block**

Open `backend/app/Console/Commands/SyncGameData.php`. Add the new mapper and model imports near the top:

```php
use App\Blizzard\Mappers\GameDataMountMapper;
use App\Models\GameDataMount;
```

Update the `handle()` method's `match` arm to dispatch to `syncMounts`. Replace the existing `match` with:

```php
        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                'mounts' => $this->syncMounts($client, $mountMapper),
                default => $this->error("Unknown resource: {$r}") || self::FAILURE,
            };
        }
```

(The `'titles'` arm and `$titleMapper` parameter were added by the titles slice — preserve them. Only the `'mounts'` arm and the `$mountMapper` parameter are new in this slice.)

Add `GameDataMountMapper $mountMapper` to the `handle()` method's parameter list. The full signature now reads:

```php
    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
        GameDataTitleMapper $titleMapper,
        GameDataMountMapper $mountMapper,
    ): int {
```

- [ ] **Step 2: Append the `syncMounts` private method**

Add the following private method to the same class (place it after `syncTitles`, before the closing brace):

```php
    private function syncMounts(
        BlizzardGameDataClient $client,
        GameDataMountMapper $mapper,
    ): void {
        $this->info('Syncing mounts...');

        // The container resolves a region-bound instance — see
        // BlizzardServiceProvider::register() — so we don't set it here.
        // For multi-region sync, pass a per-region instance: see
        // SyncCharacterData::handle() (line ~178) for the per-region
        // construction pattern.

        $index = $client->getMountIndex();
        if ($index === null) {
            $this->warn('Mount index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' mount IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getMount($id);
                } catch (Throwable $e) {
                    Log::warning("Mount sync skipped id={$id}: ".$e->getMessage());
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

                GameDataMount::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'description' => $dto->description,
                        'source_text' => $dto->sourceText,
                        'summon_spell_id' => $dto->summonSpellId,
                        'item_id' => $dto->itemId,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Mounts synced: {$upserted} upserted, {$skipped} skipped.");
    }
```

- [ ] **Step 3: Verify the command still registers**

Run:
```bash
php artisan list | grep blizzard:sync-game-data
```

Expected: command listed.

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Console/Commands/SyncGameData.php
git commit -m "feat(plan-5): extend blizzard:sync-game-data with mounts arm"
```

---

## Task 9: Artisan command test — append mounts cases to `SyncGameDataTest`

**Files:**
- Modify: `tests/Feature/Console/SyncGameDataTest.php`

- [ ] **Step 1: Add the import**

In the existing `SyncGameDataTest` (created by the factions slice and extended by the titles slice), ensure the new model is imported near the top:

```php
use App\Models\GameDataMount;
```

- [ ] **Step 2: Append the mounts tests**

Append the following test methods inside the class:

```php
    public function test_sync_mounts_upserts_full_detail(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMountIndex')->willReturn([
            'mounts' => [
                ['id' => 6, 'name' => 'Onyxian Drake'],
                ['id' => 219, 'name' => 'Tawny Wind Rider'],
            ],
        ]);
        $mock->method('getMount')->willReturnCallback(function (int $id): array {
            return match ($id) {
                6 => [
                    'id' => 6,
                    'name' => 'Onyxian Drake',
                    'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
                    'summon_spell' => ['id' => 69395],
                    'item' => ['id' => 49636],
                ],
                219 => [
                    'id' => 219,
                    'name' => 'Tawny Wind Rider',
                    'source' => ['type' => 'VENDOR'],
                    'summon_spell' => ['id' => 32243],
                ],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataMount::count());

        $onyxia = GameDataMount::find(6);
        $this->assertNotNull($onyxia);
        $this->assertSame('Onyxian Drake', $onyxia->name);
        $this->assertSame('Drop: Onyxia', $onyxia->source_text);
        $this->assertSame(69395, $onyxia->summon_spell_id);
        $this->assertSame(49636, $onyxia->item_id);

        $tawny = GameDataMount::find(219);
        $this->assertSame('Vendor', $tawny->source_text);
        $this->assertSame(32243, $tawny->summon_spell_id);
        $this->assertNull($tawny->item_id);
    }

    public function test_sync_mounts_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMountIndex')->willReturn([
            'mounts' => [['id' => 6, 'name' => 'Onyxian Drake']],
        ]);
        $mock->method('getMount')->willReturn([
            'id' => 6,
            'name' => 'Onyxian Drake',
            'source' => ['type' => 'DROP', 'name' => 'Onyxia'],
            'summon_spell' => ['id' => 69395],
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts']);

        $this->assertSame(1, GameDataMount::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_mounts_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getMountIndex')->willReturn([
            'mounts' => [
                ['id' => 6, 'name' => 'A'],
                ['id' => 219, 'name' => 'B'],
            ],
        ]);
        $mock->method('getMount')->willReturnCallback(function (int $id): ?array {
            if ($id === 6) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B'];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'mounts'])
            ->assertExitCode(0);

        $this->assertNull(GameDataMount::find(6));
        $this->assertNotNull(GameDataMount::find(219));
    }
```

- [ ] **Step 3: Run the new tests**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Console/SyncGameDataTest.php
```

Expected: all tests pass — the 3 new mount tests plus all earlier (factions + titles).

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Feature/Console/SyncGameDataTest.php
git commit -m "test(plan-5): cover blizzard:sync-game-data mounts resource"
```

---

## Task 10: `CharacterMount` model — add `gameData()` relation

**Files:**
- Modify: `app/Models/CharacterMount.php`

- [ ] **Step 1: Add the relation**

Open `backend/app/Models/CharacterMount.php` and append after the `character()` method:

```php
    public function gameData(): BelongsTo
    {
        return $this->belongsTo(GameDataMount::class, 'mount_id');
    }
```

The full file should read:

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

    public function gameData(): BelongsTo
    {
        return $this->belongsTo(GameDataMount::class, 'mount_id');
    }
}
```

- [ ] **Step 2: Smoke-test the relation in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\CharacterMount;
use App\Models\GameDataMount;
GameDataMount::firstOrCreate(['id' => 6], ['name' => 'Onyxian Drake', 'summon_spell_id' => 69395]);
\$cm = new CharacterMount([
  'character_id' => 1,
  'mount_id' => 6,
  'name' => 'Onyxian Drake',
  'is_useable' => true,
]);
\$cm->setRelation('gameData', GameDataMount::find(6));
dump(\$cm->gameData->summon_spell_id);
"
```

Expected: dump prints `69395`.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Models/CharacterMount.php
git commit -m "feat(plan-5): add CharacterMount::gameData() belongsTo relation"
```

---

## Task 11: `CharacterController` — append `mounts.gameData` to eager-load

**Files:**
- Modify: `app/Http/Controllers/Api/CharacterController.php` (or wherever the show endpoint lives — verify with `find app -name "CharacterController.php"`)

- [ ] **Step 1: Find the controller and the existing eager-load list**

Run:
```bash
find backend/app -name "CharacterController.php"
```

Open the file. The factions slice added `'reputations.faction.expansion'` and the titles slice added `'titles.gameData'` to the existing eager-load call (`loadMissing(...)` or `with(...)` on the character).

- [ ] **Step 2: Append `'mounts.gameData'` to that list**

Add `'mounts.gameData'` to the same eager-load list. Example (preserve all existing entries):

```php
$character->loadMissing([
    // ...existing relations preserved (titles.gameData, reputations.faction.expansion, etc.)...
    'mounts.gameData',
]);
```

- [ ] **Step 3: Run the existing endpoint test to confirm nothing breaks**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected: existing tests still pass.

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Http/Controllers/
git commit -m "feat(plan-5): eager-load mounts.gameData on character show"
```

---

## Task 12: `MountResource` — expose `game_data` block via `whenLoaded`

**Files:**
- Modify: `app/Http/Resources/MountResource.php`

- [ ] **Step 1: Update the resource**

Replace the contents of `backend/app/Http/Resources/MountResource.php`:

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
            'game_data' => $this->whenLoaded('gameData', fn () => [
                'description' => $this->gameData->description,
                'source_text' => $this->gameData->source_text,
                'summon_spell_id' => $this->gameData->summon_spell_id !== null
                    ? (int) $this->gameData->summon_spell_id
                    : null,
                'item_id' => $this->gameData->item_id !== null
                    ? (int) $this->gameData->item_id
                    : null,
            ]),
        ];
    }
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Resources/MountResource.php
git commit -m "feat(plan-5): expose game_data block in MountResource"
```

---

## Task 13: Endpoint test — assert `game_data` block on mount responses

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Confirm the existing helper for fixture characters**

Run:
```bash
grep -n "createTestCharacter\|protected function makeCharacter" backend/tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Note the helper name your existing test uses (the factions slice's tests already integrated with it; mirror the same name).

- [ ] **Step 2: Append focused tests**

Append the following tests inside the test class. Replace `createTestCharacter` with whichever helper your file uses:

```php
    public function test_mount_response_includes_game_data_block(): void
    {
        \App\Models\GameDataMount::create([
            'id' => 6,
            'name' => 'Onyxian Drake',
            'description' => 'A drake born of Onyxia\'s brood.',
            'source_text' => 'Drop: Onyxia',
            'summon_spell_id' => 69395,
            'item_id' => 49636,
        ]);

        $character = $this->createTestCharacter();
        $character->mounts()->create([
            'mount_id' => 6,
            'name' => 'Onyxian Drake',
            'is_useable' => true,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertOk();
        $response->assertJsonPath('data.mounts.0.mount_id', 6);
        $response->assertJsonPath('data.mounts.0.game_data.description', "A drake born of Onyxia's brood.");
        $response->assertJsonPath('data.mounts.0.game_data.source_text', 'Drop: Onyxia');
        $response->assertJsonPath('data.mounts.0.game_data.summon_spell_id', 69395);
        $response->assertJsonPath('data.mounts.0.game_data.item_id', 49636);
    }

    public function test_mount_response_omits_game_data_block_when_no_row(): void
    {
        $character = $this->createTestCharacter();
        $character->mounts()->create([
            'mount_id' => 99999, // no game_data_mounts row
            'name' => 'Orphan Mount',
            'is_useable' => true,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertJsonPath('data.mounts.0.mount_id', 99999);
        // belongsTo with no matching row → relation null → whenLoaded emits no key.
        $response->assertJsonMissingPath('data.mounts.0.game_data');
    }

    public function test_mount_response_handles_partial_game_data(): void
    {
        \App\Models\GameDataMount::create([
            'id' => 219,
            'name' => 'Tawny Wind Rider',
            'description' => null,
            'source_text' => 'Vendor',
            'summon_spell_id' => 32243,
            'item_id' => null,
        ]);

        $character = $this->createTestCharacter();
        $character->mounts()->create([
            'mount_id' => 219,
            'name' => 'Tawny Wind Rider',
            'is_useable' => true,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertJsonPath('data.mounts.0.game_data.description', null);
        $response->assertJsonPath('data.mounts.0.game_data.source_text', 'Vendor');
        $response->assertJsonPath('data.mounts.0.game_data.summon_spell_id', 32243);
        $response->assertJsonPath('data.mounts.0.game_data.item_id', null);
    }
```

- [ ] **Step 3: Run the new tests**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php --filter=mount_response
```

Expected: 3 new tests pass.

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "test(plan-5): assert game_data block on mount responses"
```

---

## Task 14: Run the sync command locally to populate dev data

**Files:** none (operational only — produces DB rows)

- [ ] **Step 1: Run the sync command for mounts**

Run:
```bash
php artisan blizzard:sync-game-data mounts
```

Expected: progress bar runs (~1.2k IDs), "N upserted, M skipped" message at end. May take a minute or two — Blizzard rate-limited.

- [ ] **Step 2: Verify rows landed**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataMount;
dump('total: '.GameDataMount::count());
dump('with summon_spell_id: '.GameDataMount::whereNotNull('summon_spell_id')->count());
dump('with source_text: '.GameDataMount::whereNotNull('source_text')->count());
\$onyxia = GameDataMount::find(6);
if (\$onyxia) {
    dump(['name' => \$onyxia->name, 'source' => \$onyxia->source_text, 'spell' => \$onyxia->summon_spell_id]);
}
"
```

Expected: total ≈ 1200, most rows have a `summon_spell_id`, many have a `source_text`. If `Onyxian Drake` (id 6) is present, dump shows its source as a Drop and a non-null spell ID.

- [ ] **Step 3: No commit (DB state, not code)**

---

## Task 15: Frontend — extend `Mount` TS type

**Files:**
- Modify: `frontend/src/types/character.ts` (the `Mount` interface, currently around lines 168-172)

- [ ] **Step 1: Add `MountGameData` and update `Mount`**

In `frontend/src/types/character.ts`, locate the existing `Mount` interface:

```typescript
export interface Mount {
  mount_id: number
  name: string
  is_useable: boolean
}
```

Replace it with:

```typescript
export interface MountGameData {
  description: string | null
  source_text: string | null
  summon_spell_id: number | null
  item_id: number | null
}

export interface Mount {
  mount_id: number
  name: string
  is_useable: boolean
  game_data?: MountGameData | null
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
git commit -m "feat(plan-5): add MountGameData and extend Mount type"
```

---

## Task 16: Frontend — update `MountsSubtab.vue` to render hydrated metadata

**Files:**
- Modify: `frontend/src/pages/character/collections/MountsSubtab.vue`

- [ ] **Step 1: Replace the file**

Replace the contents of `frontend/src/pages/character/collections/MountsSubtab.vue`:

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
        <div class="flex min-w-0 flex-1 flex-col">
          <component
            :is="wowheadHrefFor(m) ? 'a' : 'span'"
            v-bind="wowheadAttrsFor(m)"
            class="truncate"
          >
            {{ m.name }}
          </component>
          <span
            v-if="m.game_data?.source_text"
            class="truncate text-xs opacity-60"
          >
            {{ m.game_data.source_text }}
          </span>
        </div>
        <span v-if="!m.is_useable" class="badge badge-ghost badge-sm">unusable</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Mountain } from 'lucide-vue-next'
import type { Mount } from '@/types/character'
import { useCharacterContext } from '@/composables/useCharacterContext'

const { character } = useCharacterContext()

const mounts = computed(() => character.value.mounts ?? [])
const hasMounts = computed(() => mounts.value.length > 0)

/**
 * Build a Wowhead href for a mount when we have its summon_spell_id; this
 * gives the user the canonical mount tooltip on hover via power.js.
 * Falls back to no href (plain <span>) if the spell ID isn't resolved yet.
 */
function wowheadHrefFor(m: Mount): string | null {
  const id = m.game_data?.summon_spell_id
  return typeof id === 'number' ? `https://www.wowhead.com/spell=${id}` : null
}

function wowheadAttrsFor(m: Mount): Record<string, string> {
  const href = wowheadHrefFor(m)
  if (!href) return {}
  const id = m.game_data!.summon_spell_id!
  return {
    href,
    'data-wowhead': `spell=${id}`,
    target: '_blank',
    rel: 'noopener',
  }
}
</script>
```

The structural changes vs. the previous version:
1. Each mount now renders inside a flex column so we can stack a `source_text` caption under the name.
2. The mount name itself becomes a Wowhead-tooltip-bearing anchor when `game_data.summon_spell_id` is present (renders the full mount tooltip from Wowhead's CDN); otherwise stays a plain `<span>` and matches the previous behavior.

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
git add src/pages/character/collections/MountsSubtab.vue
git commit -m "feat(plan-5): render mount source_text and summon_spell tooltip"
```

---

## Task 17: Manual smoke test in dev

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

- [ ] **Step 2: Look up a character with mounts collected**

In the browser, navigate to a character that has Plan 4 mounts data (e.g., the test character used during Plan 4 ramp). Click **Collections > Mounts**.

Expected:
- The mount list renders. Each card shows the mount name and (when game data is present) a small caption like "Drop: Onyxia" or "Vendor" or "Quest: <name>" beneath the name.
- Hovering the mount name fires the Wowhead tooltip (the standard mount tooltip, not just a plain link). Verify by hovering — should see the rich Wowhead popover.
- Mounts that don't have `game_data` (rare, only if a brand-new patch dropped between sync runs) render plainly, like before — no broken layout.

- [ ] **Step 3: Inspect the network response**

Open DevTools > Network. Look at the `/api/v1/characters/...` payload for the character. Inside `data.mounts[]`, each mount that has a corresponding `game_data_mounts` row should now carry a `game_data: { description, source_text, summon_spell_id, item_id }` block.

- [ ] **Step 4: No commit (manual step only)**

---

## Task 18: Final BE + FE verification

**Files:** none (test runs only)

- [ ] **Step 1: Full BE test suite**

Run:
```bash
cd backend
composer test
```

Expected: all tests pass — Plan 4's existing 51 + factions slice's ~14 + titles slice's ~12 + this slice's ~16 (10 mapper, 4 client, 3 endpoint, 3 command — counted as 16 unique test methods this slice adds).

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

## Task 19: Update CLAUDE.md (backend) with the new slice notes

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Add the slice bullet**

Open `backend/CLAUDE.md`. The "## Architecture > ### Blizzard Module" bullet list now includes "Game-data factions resolver (Plan 5)" (added by the factions slice) and "Game-data titles resolver (Plan 5)" (added by the titles slice). Append a new bullet after them:

```markdown
- **Game-data mounts resolver (Plan 5).** `game_data_mounts` (synced from `/data/wow/mount/{index,id}` in the **`static-{region}`** namespace by `php artisan blizzard:sync-game-data mounts`, scheduled weekly with the rest) hydrates `CharacterMount` via a new `gameData()` `belongsTo` relation. `MountResource` exposes `game_data.{description,source_text,summon_spell_id,item_id}` via `whenLoaded`. `source_text` is a flattened `"<Type>: <Name>"` rendering of Blizzard's `source: { type, name }` object (e.g., "Drop: Onyxia", "Quest: A Mighty Steed"). `summon_spell_id` is what `MountsSubtab.vue` uses for the Wowhead `spell=` tooltip — when present, the mount name becomes a Wowhead-tooltip-bearing anchor; when absent, the mount renders as plain text (preserving pre-Plan-5 behavior). No feature flag — eager-load is unconditional.
```

- [ ] **Step 2: Commit**

Run:
```bash
git add CLAUDE.md
git commit -m "docs(plan-5): document mounts slice"
```

---

## Task 20: Final review pass

**Files:** none (review only)

- [ ] **Step 1: Confirm all commits land on the feature branch**

Run:
```bash
git log master..HEAD --oneline | grep "plan-5" | head -50
```

Expected: factions slice commits, titles slice commits, then this slice's ~12 commits ranging from "feat(plan-5): add game_data_mounts table" to "docs(plan-5): document mounts slice".

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

(The branch already has a remote tracking set from the factions slice's first push.)

- [ ] **Step 4: Update or open the PR**

If a Plan-5 PR is already open from a previous slice, the push automatically updates it — leave a comment summarizing what this slice adds. Title remains the umbrella `Plan 5 — game-data resolver`. Body should reference this plan: `backend/docs/superpowers/plans/2026-04-30-plan-5-mounts-slice.md`.

If the branch is being merged per-slice, open a fresh PR scoped to just the mount commits.

The next sub-slice (`plan-5-achievements`) ships next, on the same branch. The cleanup slice ships last, gated on Plan 4 ramp verification in prod.
