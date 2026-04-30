# Plan 5 — Titles Slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-30-plan-5-game-data-resolver-design.md` (sub-slice 2 in §2.7 — titles).

**Goal:** Hydrate `CharacterTitle` rows with gender-aware title strings from `/data/wow/title/{id}`. The character `/titles` endpoint returns a single `display_string` whose gendered form is fixed at sync time; this slice resolves both `name_male` and `name_female` once per title and lets the FE render whichever variant matches `character.gender`.

**Architecture:** One new table (`game_data_titles`) populated by extending the `blizzard:sync-game-data` Artisan command (built in plan-5-factions) with a `titles` arm. New `BlizzardGameDataClient` methods `getTitleIndex()` + `getTitle(int $id)` follow the static-namespace + 7-day-cache pattern established in plan-5-factions. New DTO + mapper handle the `gender_name.{male,female}` extraction (mapper falls back to `name` for both columns when `gender_name` is absent). `CharacterTitle` Eloquent model gains a `gameData()` `belongsTo` relation; `CharacterTitleResource` exposes a `gameData` block via `whenLoaded`. FE `CharacterTitle` type gains `game_data: { name_male, name_female } | null`; `CharacterTitlesTab.vue` picks the right variant from `character.gender`, falling back to the existing `display_string` when `game_data` is null. No feature flag — eager-load is unconditional.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Vue 3 + TS + DaisyUI.

**Out of scope:** Anything that is not a title. No factions, mounts, achievements, cleanup work — those have their own plan docs.

**Sequencing:** Sub-slice 2. Builds directly on plan-5-factions, which created:
- `feature/plan-5-game-data-resolver` branch (still active — no new branch)
- `BlizzardGameDataClient::getFactionIndex/getFaction` (provides the static-namespace pattern this slice mirrors)
- `app/Console/Commands/SyncGameData.php` (this slice extends its `match` arm)
- `config/blizzard.php :: game_data_cache_ttl` (this slice reuses)
- `CharacterController` eager-load list (this slice appends `titles.gameData`)
- backend CLAUDE.md "Game-data factions resolver (Plan 5)" bullet (this slice adds a sibling)

**Deploy-ready at the end of:** this plan, after running `php artisan migrate && php artisan blizzard:sync-game-data titles` in each environment.

---

## Task 1: Confirm we're on the Plan 5 branch

**Files:** none (git only)

- [ ] **Step 1: Verify branch**

Run:
```bash
cd backend
git branch --show-current
```

Expected: `feature/plan-5-game-data-resolver`. If it prints anything else, switch to it (the branch was created in plan-5-factions Task 1).

- [ ] **Step 2: Confirm factions slice landed**

Run:
```bash
git log --oneline | grep -i "plan-5" | head -10
```

Expected: commits from plan-5-factions are present (e.g. "feat(plan-5): add game_data_factions table", "feat(plan-5): add blizzard:sync-game-data Artisan command (factions support)"). If they are missing, plan-5-factions has not been executed yet — execute it before continuing.

---

## Task 2: Migration — `game_data_titles` table

**Files:**
- Create: `database/migrations/2026_04_30_100003_create_game_data_titles_table.php`

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_04_30_100003_create_game_data_titles_table.php`:

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
        Schema::create('game_data_titles', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name_male', 255);
            $table->string('name_female', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_titles');
    }
};
```

- [ ] **Step 2: Run the migration locally**

Run:
```bash
php artisan migrate
```

Expected: migration runs, no errors. `game_data_titles` table exists.

- [ ] **Step 3: Verify schema**

Run:
```bash
php artisan tinker --execute="dd(Schema::getColumnListing('game_data_titles'));"
```

Expected: `["id", "name_male", "name_female", "created_at", "updated_at"]`.

- [ ] **Step 4: Commit**

Run:
```bash
git add database/migrations/2026_04_30_100003_create_game_data_titles_table.php
git commit -m "feat(plan-5): add game_data_titles table"
```

---

## Task 3: Eloquent model — `GameDataTitle`

**Files:**
- Create: `app/Models/GameDataTitle.php`

- [ ] **Step 1: Write the model**

Create `backend/app/Models/GameDataTitle.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataTitle extends Model
{
    protected $fillable = ['id', 'name_male', 'name_female'];

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

- [ ] **Step 2: Smoke-test the model in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataTitle;
\$t = GameDataTitle::create(['id' => 999999, 'name_male' => '{name}, the Test', 'name_female' => '{name}, the Test']);
dump(\$t->name_male, \$t->name_female);
\$t->delete();
"
```

Expected: dump prints both strings; row cleans up.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Models/GameDataTitle.php
git commit -m "feat(plan-5): add GameDataTitle model"
```

---

## Task 4: DTO — `GameDataTitle`

**Files:**
- Create: `app/Blizzard/DTO/GameDataTitle.php`

- [ ] **Step 1: Write the DTO**

Create `backend/app/Blizzard/DTO/GameDataTitle.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataTitle
{
    public function __construct(
        public int $id,
        public string $nameMale,
        public string $nameFemale,
    ) {}
}
```

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Blizzard/DTO/GameDataTitle.php
git commit -m "feat(plan-5): add GameDataTitle DTO"
```

---

## Task 5: Mapper — `GameDataTitleMapper`

**Files:**
- Create: `app/Blizzard/Mappers/GameDataTitleMapper.php`

- [ ] **Step 1: Write the mapper**

Create `backend/app/Blizzard/Mappers/GameDataTitleMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Blizzard\DTO\GameDataTitle;

class GameDataTitleMapper
{
    /**
     * Map a single Blizzard /data/wow/title/{id} response to a GameDataTitle DTO.
     *
     * Blizzard's title detail endpoint exposes:
     *   { id, name, gender_name: { male, female } }
     *
     * Most titles ship gender-neutral copy in both gender_name slots
     * (e.g. "the Hallowed" reads identically). Some titles do diverge —
     * "Lord {name}" vs "Lady {name}" — and those are the load-bearing case
     * for this slice.
     *
     * Some legacy or partial responses omit `gender_name` entirely; in
     * that case we fall back to `name` for both columns so the FE always
     * has something to render and downstream code does not need to handle
     * empty strings.
     */
    public function mapDetail(?array $data): ?GameDataTitle
    {
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        $fallback = (string) ($data['name'] ?? '');

        $male = isset($data['gender_name']['male'])
            ? (string) $data['gender_name']['male']
            : $fallback;

        $female = isset($data['gender_name']['female'])
            ? (string) $data['gender_name']['female']
            : $fallback;

        return new GameDataTitle(
            id: (int) $data['id'],
            nameMale: $male,
            nameFemale: $female,
        );
    }

    /**
     * Extract title IDs from a /data/wow/title/index response.
     *
     * @return int[]
     */
    public function extractIndexIds(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $out = [];
        foreach ($data['titles'] ?? [] as $entry) {
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
git add app/Blizzard/Mappers/GameDataTitleMapper.php
git commit -m "feat(plan-5): add GameDataTitleMapper with gender_name extraction"
```

---

## Task 6: Mapper test — `GameDataTitleMapperTest`

**Files:**
- Create: `tests/Unit/Blizzard/Mappers/GameDataTitleMapperTest.php`

- [ ] **Step 1: Write the test**

Create `backend/tests/Unit/Blizzard/Mappers/GameDataTitleMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataTitleMapper;
use PHPUnit\Framework\TestCase;

class GameDataTitleMapperTest extends TestCase
{
    private GameDataTitleMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataTitleMapper();
    }

    public function test_extracts_gender_specific_strings_when_present(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 414,
            'name' => '{name}, the Bear',
            'gender_name' => [
                'male' => '{name}, Lord of the Bears',
                'female' => '{name}, Lady of the Bears',
            ],
        ]);

        $this->assertSame(414, $dto->id);
        $this->assertSame('{name}, Lord of the Bears', $dto->nameMale);
        $this->assertSame('{name}, Lady of the Bears', $dto->nameFemale);
    }

    public function test_falls_back_to_name_when_gender_name_missing(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 100,
            'name' => '{name}, the Hallowed',
        ]);

        $this->assertSame('{name}, the Hallowed', $dto->nameMale);
        $this->assertSame('{name}, the Hallowed', $dto->nameFemale);
    }

    public function test_falls_back_to_name_when_gender_name_partial(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 200,
            'name' => '{name}, Champion',
            'gender_name' => [
                'male' => '{name}, the Champion',
                // no 'female' key
            ],
        ]);

        $this->assertSame('{name}, the Champion', $dto->nameMale);
        $this->assertSame('{name}, Champion', $dto->nameFemale);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'Anonymous']));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'titles' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 414, 'name' => 'B'],
                ['name' => 'C-no-id'], // skipped
            ],
        ]);

        $this->assertSame([1, 414], $ids);
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
./vendor/bin/phpunit tests/Unit/Blizzard/Mappers/GameDataTitleMapperTest.php
```

Expected: 7 tests pass.

- [ ] **Step 3: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Mappers/GameDataTitleMapperTest.php
git commit -m "test(plan-5): cover GameDataTitleMapper with 7 cases"
```

---

## Task 7: Client methods — `BlizzardGameDataClient::getTitleIndex()` and `getTitle()`

**Files:**
- Modify: `app/Blizzard/Client/BlizzardGameDataClient.php`

- [ ] **Step 1: Add the two methods**

Open `backend/app/Blizzard/Client/BlizzardGameDataClient.php` and append the following methods inside the class, after `getFaction()` (added in plan-5-factions Task 9):

```php
    /**
     * Fetch the title index from /data/wow/title/index.
     * Returns the raw response array; mapper extracts IDs.
     *
     * static-{region} namespace, 7-day cache — same precedent as
     * getFactionIndex() and getTalentTree().
     */
    public function getTitleIndex(): ?array
    {
        $cacheKey = "blizzard:game-data:title-index:{$this->region}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function (): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/title/index");

            if ($response->status() === 404) {
                return null;
            }
            $response->throw();

            return $response->json();
        });
    }

    /**
     * Fetch a single title by ID from /data/wow/title/{id}.
     * Response carries `gender_name: { male, female }` for gendered titles.
     */
    public function getTitle(int $id): ?array
    {
        $cacheKey = "blizzard:game-data:title:{$this->region}:{$id}";
        $ttl = (int) config('blizzard.game_data_cache_ttl', 86400 * 7);

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            $response = Http::withToken($this->tokenManager->getToken($this->region))
                ->withQueryParameters([
                    'namespace' => "static-{$this->region}",
                    'locale' => 'en_GB',
                ])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get("{$this->baseUrl()}/data/wow/title/{$id}");

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
git commit -m "feat(plan-5): add getTitleIndex and getTitle methods to BlizzardGameDataClient"
```

(`config/blizzard.php :: game_data_cache_ttl` and the `.env.example` entry already exist from plan-5-factions Task 9 — no change needed here.)

---

## Task 8: Client method tests — extend `BlizzardGameDataClientTest`

**Files:**
- Modify: `tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php`

- [ ] **Step 1: Append `getTitleIndex` tests**

Open `backend/tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php` (created/extended in plan-5-factions Task 10). Append inside the test class:

```php
    public function test_get_title_index_returns_response_in_static_namespace(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/index?*' => Http::response([
                'titles' => [
                    ['id' => 1, 'name' => 'Private'],
                    ['id' => 414, 'name' => '{name}, the Bear'],
                ],
            ], 200),
        ]);

        $result = $this->client()->getTitleIndex();

        $this->assertSame(1, $result['titles'][0]['id']);
        $this->assertSame(414, $result['titles'][1]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/data/wow/title/index')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_title_index_caches_within_ttl(): void
    {
        Cache::flush();
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['titles' => []], 200);
        });

        $client = $this->client();
        $client->getTitleIndex();
        $client->getTitleIndex();

        $this->assertSame(1, $callCount, 'second call should be served from cache');
    }

    public function test_get_title_index_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/index?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getTitleIndex());
    }
```

- [ ] **Step 2: Append `getTitle` tests**

Append inside the same test class:

```php
    public function test_get_title_returns_gender_name_payload(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/414?*' => Http::response([
                'id' => 414,
                'name' => '{name}, the Bear',
                'gender_name' => [
                    'male' => '{name}, Lord of the Bears',
                    'female' => '{name}, Lady of the Bears',
                ],
            ], 200),
        ]);

        $result = $this->client()->getTitle(414);

        $this->assertSame(414, $result['id']);
        $this->assertSame('{name}, Lord of the Bears', $result['gender_name']['male']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/data/wow/title/414')
                && str_contains($request->url(), 'namespace=static-us')
                && str_contains($request->url(), 'locale=en_GB');
        });
    }

    public function test_get_title_returns_null_on_404(): void
    {
        Cache::flush();

        Http::fake([
            'us.api.blizzard.com/data/wow/title/99999?*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->client()->getTitle(99999));
    }
```

- [ ] **Step 3: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
```

Expected: all tests pass — pre-existing factions tests + 5 new title tests.

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Unit/Blizzard/Client/BlizzardGameDataClientTest.php
git commit -m "test(plan-5): cover getTitleIndex and getTitle client methods"
```

---

## Task 9: Extend `SyncGameData` Artisan command with the `titles` arm

**Files:**
- Modify: `app/Console/Commands/SyncGameData.php`

- [ ] **Step 1: Add the imports**

Open `backend/app/Console/Commands/SyncGameData.php` (created in plan-5-factions Task 11). At the top of the `use` block, add:

```php
use App\Blizzard\Mappers\GameDataTitleMapper;
use App\Models\GameDataTitle;
```

- [ ] **Step 2: Add `GameDataTitleMapper` to `handle()`'s constructor injection**

Replace the existing `handle()` signature so it accepts the title mapper too. The full updated `handle()` body should look like this — preserve the existing factions arm verbatim:

```php
    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
        GameDataTitleMapper $titleMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'titles']
            : [$resource];

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                default => $this->error("Unknown resource: {$r}") || self::FAILURE,
            };
        }

        return self::SUCCESS;
    }
```

(The "no resource argument" default now sweeps both `factions` and `titles`. Mounts and achievements will append themselves in their own slices.)

- [ ] **Step 3: Add the `syncTitles` private method**

Append after the existing `syncFactions(...)` method:

```php
    private function syncTitles(
        BlizzardGameDataClient $client,
        GameDataTitleMapper $mapper,
    ): void {
        $this->info('Syncing titles...');

        $index = $client->getTitleIndex();
        if ($index === null) {
            $this->warn('Title index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' title IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getTitle($id);
                } catch (Throwable $e) {
                    Log::warning("Title sync skipped id={$id}: ".$e->getMessage());
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

                GameDataTitle::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name_male' => $dto->nameMale,
                        'name_female' => $dto->nameFemale,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Titles synced: {$upserted} upserted, {$skipped} skipped.");
    }
```

- [ ] **Step 4: Verify the command still registers**

Run:
```bash
php artisan list | grep blizzard:sync-game-data
```

Expected: command appears.

- [ ] **Step 5: Verify the signature accepts `titles`**

Run:
```bash
php artisan blizzard:sync-game-data --help
```

Expected: argument description mentions `factions|titles|mounts|achievements; omit for all`.

- [ ] **Step 6: Commit**

Run:
```bash
git add app/Console/Commands/SyncGameData.php
git commit -m "feat(plan-5): add titles arm to blizzard:sync-game-data"
```

---

## Task 10: Artisan command test — extend `SyncGameDataTest` with title coverage

**Files:**
- Modify: `tests/Feature/Console/SyncGameDataTest.php`

- [ ] **Step 1: Add the imports**

Open `backend/tests/Feature/Console/SyncGameDataTest.php` (created in plan-5-factions Task 12). Add to the `use` block:

```php
use App\Models\GameDataTitle;
```

- [ ] **Step 2: Add the title tests**

Append inside the test class:

```php
    public function test_sync_titles_upserts_gender_specific_strings(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getTitleIndex')->willReturn([
            'titles' => [
                ['id' => 414, 'name' => '{name}, the Bear'],
                ['id' => 100, 'name' => '{name}, the Hallowed'],
            ],
        ]);
        $mock->method('getTitle')->willReturnCallback(function (int $id): array {
            return match ($id) {
                414 => [
                    'id' => 414,
                    'name' => '{name}, the Bear',
                    'gender_name' => [
                        'male' => '{name}, Lord of the Bears',
                        'female' => '{name}, Lady of the Bears',
                    ],
                ],
                100 => [
                    'id' => 100,
                    'name' => '{name}, the Hallowed',
                ],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles'])
            ->assertExitCode(0);

        $this->assertSame(2, GameDataTitle::count());

        $bear = GameDataTitle::find(414);
        $this->assertNotNull($bear);
        $this->assertSame('{name}, Lord of the Bears', $bear->name_male);
        $this->assertSame('{name}, Lady of the Bears', $bear->name_female);

        $hallowed = GameDataTitle::find(100);
        $this->assertSame(
            '{name}, the Hallowed',
            $hallowed->name_male,
            'name_male falls back to name when gender_name absent',
        );
        $this->assertSame('{name}, the Hallowed', $hallowed->name_female);
    }

    public function test_sync_titles_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getTitleIndex')->willReturn([
            'titles' => [['id' => 414, 'name' => '{name}, the Bear']],
        ]);
        $mock->method('getTitle')->willReturn([
            'id' => 414,
            'name' => '{name}, the Bear',
            'gender_name' => [
                'male' => '{name}, Lord of the Bears',
                'female' => '{name}, Lady of the Bears',
            ],
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles']);

        $this->assertSame(1, GameDataTitle::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_titles_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getTitleIndex')->willReturn([
            'titles' => [
                ['id' => 414, 'name' => 'A'],
                ['id' => 100, 'name' => 'B'],
            ],
        ]);
        $mock->method('getTitle')->willReturnCallback(function (int $id): ?array {
            if ($id === 414) {
                throw new \RuntimeException('simulated transient failure');
            }

            return [
                'id' => $id,
                'name' => 'B',
                'gender_name' => ['male' => 'B', 'female' => 'B'],
            ];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'titles'])
            ->assertExitCode(0);

        $this->assertNull(GameDataTitle::find(414));
        $this->assertNotNull(GameDataTitle::find(100), 'second title still upserted');
    }
```

- [ ] **Step 3: Run the test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Console/SyncGameDataTest.php
```

Expected: all tests pass — pre-existing 3 factions tests + 3 new title tests.

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Feature/Console/SyncGameDataTest.php
git commit -m "test(plan-5): cover blizzard:sync-game-data titles resource"
```

---

## Task 11: `CharacterTitle` model — add `gameData()` relation

**Files:**
- Modify: `app/Models/CharacterTitle.php`

- [ ] **Step 1: Add the relation**

Open `backend/app/Models/CharacterTitle.php` and append after the `character()` method:

```php
    public function gameData(): BelongsTo
    {
        return $this->belongsTo(GameDataTitle::class, 'title_id');
    }
```

The `BelongsTo` import is already present (used by `character()`); you only need to add a new `use` for `GameDataTitle`:

```php
use App\Models\GameDataTitle;
```

(Already in `App\Models` namespace, but the `use` makes it explicit and Pint-friendly. If your existing convention omits same-namespace `use` statements, skip the import and reference `GameDataTitle::class` directly.)

- [ ] **Step 2: Smoke-test the relation in tinker**

Run:
```bash
php artisan tinker --execute="
use App\Models\CharacterTitle;
use App\Models\GameDataTitle;
GameDataTitle::firstOrCreate(['id' => 414], ['name_male' => '{name}, Lord of the Bears', 'name_female' => '{name}, Lady of the Bears']);
\$ct = new CharacterTitle([
  'character_id' => 1,
  'title_id' => 414,
  'name' => '{name}, the Bear',
  'display_string' => '{name}, the Bear',
  'is_selected' => false,
]);
\$ct->setRelation('gameData', GameDataTitle::find(414));
dump(\$ct->gameData->name_male);
GameDataTitle::find(414)->delete();
"
```

Expected: dump prints `'{name}, Lord of the Bears'`.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Models/CharacterTitle.php
git commit -m "feat(plan-5): add CharacterTitle::gameData() belongsTo relation"
```

---

## Task 12: `CharacterController` — append `titles.gameData` to the eager-load list

**Files:**
- Modify: `app/Http/Controllers/Api/CharacterController.php` (verify path with `find app -name "CharacterController.php"`)

- [ ] **Step 1: Find the controller and the existing eager-load**

Run:
```bash
find backend/app -name "CharacterController.php"
grep -n "loadMissing\|reputations.faction.expansion" backend/app/Http/Controllers/Api/CharacterController.php
```

Expected: a single path for the controller, plus a hit showing the `reputations.faction.expansion` line added in plan-5-factions Task 14.

- [ ] **Step 2: Append `titles.gameData` to the list**

Open the controller, locate the `loadMissing(...)` (or `with(...)`) call modified in plan-5-factions, and add a new entry:

```php
$character->loadMissing([
    // ...existing relations preserved (Plan 4 + plan-5-factions)...
    'reputations.faction.expansion',
    'titles.gameData',
]);
```

- [ ] **Step 3: Run the existing endpoint test**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php
```

Expected: all existing tests still pass.

- [ ] **Step 4: Commit**

Run:
```bash
git add app/Http/Controllers/
git commit -m "feat(plan-5): eager-load titles.gameData on character show"
```

---

## Task 13: `CharacterTitleResource` — expose `gameData` via `whenLoaded`

**Files:**
- Modify: `app/Http/Resources/CharacterTitleResource.php`

- [ ] **Step 1: Update the resource**

Replace the contents of `backend/app/Http/Resources/CharacterTitleResource.php`:

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
            'game_data' => $this->whenLoaded('gameData', fn () => [
                'name_male' => $this->gameData->name_male,
                'name_female' => $this->gameData->name_female,
            ]),
        ];
    }
}
```

The structure mirrors the `ReputationResource` pattern from plan-5-factions: a nested `game_data` block, only present when the relation is eager-loaded **and** the join target exists. Missing rows (new patch, sync command not yet rerun) yield no `game_data` key, and the FE falls back to `display_string`.

- [ ] **Step 2: Commit**

Run:
```bash
git add app/Http/Resources/CharacterTitleResource.php
git commit -m "feat(plan-5): expose game_data block on CharacterTitleResource"
```

---

## Task 14: Endpoint test — assert `game_data` block appears in titles response

**Files:**
- Modify: `tests/Feature/Endpoints/RetailCharacterEndpointTest.php`

- [ ] **Step 1: Locate the existing fixture helper**

Run:
```bash
grep -n "createTestCharacter\|protected function.*Character" backend/tests/Feature/Endpoints/RetailCharacterEndpointTest.php | head -10
```

Expected: a hit on whatever helper Plan 4 / plan-5-factions tests use to build a fixture character. Use the same helper below.

- [ ] **Step 2: Append the new test methods**

Append inside the test class. Adapt `createTestCharacter()` to match the helper your file already uses; if no helper exists, use whatever inline factory pattern the closest existing test in the file uses:

```php
    public function test_titles_response_includes_game_data_block(): void
    {
        \App\Models\GameDataTitle::create([
            'id' => 414,
            'name_male' => '{name}, Lord of the Bears',
            'name_female' => '{name}, Lady of the Bears',
        ]);

        $character = $this->createTestCharacter();
        $character->titles()->create([
            'title_id' => 414,
            'name' => '{name}, the Bear',
            'display_string' => '{name}, the Bear',
            'is_selected' => true,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertOk();
        $response->assertJsonPath('data.titles.0.id', 414);
        $response->assertJsonPath('data.titles.0.display_string', '{name}, the Bear');
        $response->assertJsonPath('data.titles.0.game_data.name_male', '{name}, Lord of the Bears');
        $response->assertJsonPath('data.titles.0.game_data.name_female', '{name}, Lady of the Bears');
    }

    public function test_titles_response_omits_game_data_when_no_row(): void
    {
        $character = $this->createTestCharacter();
        $character->titles()->create([
            'title_id' => 88888, // no game_data_titles row
            'name' => 'Orphan Title',
            'display_string' => 'Orphan Title',
            'is_selected' => false,
        ]);

        $response = $this->getJson("/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}");

        $response->assertJsonPath('data.titles.0.id', 88888);
        // belongsTo with no matching row → relation is null → whenLoaded emits no key.
        $response->assertJsonMissingPath('data.titles.0.game_data');
    }
```

- [ ] **Step 3: Run the tests**

Run:
```bash
./vendor/bin/phpunit tests/Feature/Endpoints/RetailCharacterEndpointTest.php --filter=titles_response
```

Expected: 2 new tests pass.

- [ ] **Step 4: Commit**

Run:
```bash
git add tests/Feature/Endpoints/RetailCharacterEndpointTest.php
git commit -m "test(plan-5): assert game_data block on titles response"
```

---

## Task 15: Run the sync command locally to populate dev data

**Files:** none (operational only — produces DB rows)

- [ ] **Step 1: Run the title sync**

Run:
```bash
php artisan blizzard:sync-game-data titles
```

Expected: progress bar runs for the ~1k titles. "N upserted, M skipped" message. Should complete in well under a minute (titles is the smallest of the four resources after factions).

- [ ] **Step 2: Verify rows landed and a known gendered title is correct**

Run:
```bash
php artisan tinker --execute="
use App\Models\GameDataTitle;
dump('total: '.GameDataTitle::count());
dump(GameDataTitle::find(414)?->name_male, GameDataTitle::find(414)?->name_female);
"
```

Expected: total > 100 (typical title count is around 1000); title id 414 ("the Bear") shows `Lord` / `Lady` strings (or whichever variant Blizzard currently returns).

- [ ] **Step 3: No commit (DB state, not code)**

---

## Task 16: Frontend — extend `CharacterTitle` TS interface

**Files:**
- Modify: `frontend/src/types/character.ts`

- [ ] **Step 1: Update the interface**

Open `frontend/src/types/character.ts`. Find the existing `CharacterTitle` interface (around line 122). Replace it with:

```typescript
export interface CharacterTitleGameData {
  name_male: string
  name_female: string
}

export interface CharacterTitle {
  id: number
  name: string
  display_string: string
  is_selected: boolean
  game_data?: CharacterTitleGameData
}
```

The `game_data` field is **optional** (not nullable) because the BE omits it entirely via `whenLoaded` when the relation is missing — the JSON does not carry an explicit `null`.

- [ ] **Step 2: Type-check**

Run:
```bash
cd ../frontend
npx vue-tsc -b
```

Expected: no errors.

- [ ] **Step 3: Commit**

Run:
```bash
git add src/types/character.ts
git commit -m "feat(plan-5): extend CharacterTitle type with optional game_data block"
```

---

## Task 17: Frontend — pick gendered variant in `CharacterTitlesTab.vue`

**Files:**
- Modify: `frontend/src/pages/character/CharacterTitlesTab.vue`

- [ ] **Step 1: Replace the `<script setup>` block**

Open `frontend/src/pages/character/CharacterTitlesTab.vue`. Replace the `<script setup>` block (lines 34-49) with:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { Crown } from 'lucide-vue-next'
import { useCharacterContext } from '@/composables/useCharacterContext'
import EmptyTab from '@/components/character/EmptyTab.vue'
import type { CharacterTitle } from '@/types/character'

const { character, freshness } = useCharacterContext()

function variantFor(title: CharacterTitle): string {
  if (title.game_data) {
    const isFemale = character.value.gender?.toLowerCase() === 'female'
    return isFemale ? title.game_data.name_female : title.game_data.name_male
  }

  return title.display_string
}

const selectedTitle = computed(() => {
  const t = character.value.titles.find((x) => x.is_selected)
  return t ? { ...t, rendered: variantFor(t) } : null
})

const otherTitles = computed(() =>
  [...character.value.titles]
    .filter((t) => !t.is_selected)
    .map((t) => ({ ...t, rendered: variantFor(t) }))
    .sort((a, b) => a.rendered.localeCompare(b.rendered)),
)
</script>
```

- [ ] **Step 2: Update the template to read `rendered`**

Replace the `<template>` block (lines 1-32) with:

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
        <span class="ma-text-heading text-lg">{{ selectedTitle.rendered }}</span>
        <span class="badge badge-primary badge-sm ml-auto">Equipped</span>
      </div>

      <div v-if="selectedTitle && otherTitles.length > 0" class="divider my-0" />

      <ul v-if="otherTitles.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <li
          v-for="title in otherTitles"
          :key="title.id"
          class="flex items-center gap-2 px-3 py-2 rounded bg-base-200/40 text-sm text-ma-muted"
        >
          {{ title.rendered }}
        </li>
      </ul>
    </div>
  </div>
</template>
```

The two structural changes vs the previous version:
- Selected title's headline now reads `selectedTitle.rendered` (gendered variant or fallback).
- Other titles render `title.rendered` and sort by `rendered` (so e.g. "Lord X" and "Lady X" sort by the variant the viewer sees).

- [ ] **Step 3: Type-check**

Run:
```bash
npx vue-tsc -b
```

Expected: no errors.

- [ ] **Step 4: Build**

Run:
```bash
npm run build
```

Expected: build green.

- [ ] **Step 5: Commit**

Run:
```bash
git add src/pages/character/CharacterTitlesTab.vue
git commit -m "feat(plan-5): pick gendered title variant from game_data on CharacterTitlesTab"
```

---

## Task 18: Manual smoke test in dev

**Files:** none (manual)

- [ ] **Step 1: Start the dev stack**

Backend (one terminal):
```bash
cd backend
composer dev
```

Frontend (another):
```bash
cd frontend
npm run dev
```

- [ ] **Step 2: Look up a male character with a known gendered title**

In the browser, navigate to a male character whose currently equipped title is gendered (e.g., one carrying "{name}, the Bear" / "Lord of the Bears"). Open the **Titles** tab.

Expected: the selected title renders the **male** variant in the heading. The other-titles list also renders male variants.

Verify via DevTools Network tab: `data.titles[*].game_data.name_male` and `name_female` are both present in the JSON.

- [ ] **Step 3: Look up a female character**

Repeat with a female character. Expected: same titles, but **female** variants render.

- [ ] **Step 4: Look up a character whose gender is recorded as something other than 'male' or 'female'** (rare — Blizzard may emit other strings). Expected: falls back to `display_string` (variantFor's else branch). No crash.

- [ ] **Step 5: No commit (manual step only)**

---

## Task 19: Final BE + FE verification

**Files:** none (test runs only)

- [ ] **Step 1: Full BE test suite**

Run:
```bash
cd backend
composer test
```

Expected: all tests pass — Plan 4 + plan-5-factions + plan-5-titles. Approximate new tests in this slice: 7 mapper + 5 client + 3 command + 2 endpoint = 17 new.

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

## Task 20: Update CLAUDE.md (backend) with the titles slice notes

**Files:**
- Modify: `backend/CLAUDE.md`

- [ ] **Step 1: Add the slice bullet**

Open `backend/CLAUDE.md`. Find the "Game-data factions resolver (Plan 5)" bullet (added in plan-5-factions Task 23). Append a sibling bullet directly after it:

```markdown
- **Game-data titles resolver (Plan 5).** `game_data_titles` (PK id; `name_male`, `name_female` strings) is synced from `/data/wow/title/{index,id}` in the **`static-{region}`** namespace by `php artisan blizzard:sync-game-data titles` (also runs as part of the no-arg sweep). Per-title gender variants live in Blizzard's `gender_name: { male, female }` object on the detail endpoint; the mapper falls back to the gender-neutral `name` for both columns when the object is absent. `CharacterTitle::gameData()` is a `belongsTo` keyed on `title_id`; `CharacterTitleResource` exposes `game_data.{name_male,name_female}` via `whenLoaded`. The FE picks the variant by `character.gender`, falling back to `display_string` if `game_data` is missing. No feature flag.
```

- [ ] **Step 2: Commit**

Run:
```bash
git add CLAUDE.md
git commit -m "docs(plan-5): document game-data titles resolver"
```

---

## Task 21: Final review pass

**Files:** none (review only)

- [ ] **Step 1: Confirm all titles-slice commits land on the feature branch**

Run:
```bash
git log master..HEAD --oneline | grep -i "title"
```

Expected: ~10 titles commits ("feat(plan-5): add game_data_titles table", "feat(plan-5): add GameDataTitle model", ..., "docs(plan-5): document game-data titles resolver").

- [ ] **Step 2: Re-run the full suite**

Run:
```bash
composer test && (cd ../frontend && npx vue-tsc -b && npm run build)
```

Expected: all green.

- [ ] **Step 3: Push**

Run:
```bash
git push
```

(`feature/plan-5-game-data-resolver` should already track origin from plan-5-factions Task 24 — `push` without `-u` works.)

- [ ] **Step 4: PR or hold**

If plan-5-factions's PR is still open, this slice's commits land on the same branch and inherit that PR. If it has merged into master and the operator wants per-slice PRs, open a fresh one titled `Plan 5 — titles slice (gender-aware title hydration)` referencing the spec and this plan. Otherwise, the operator may continue the umbrella branch model and merge after `plan-5-cleanup` is complete.

The branch keeps building toward the next sub-slices (mounts → achievements → cleanup). No final fast-forward yet.
