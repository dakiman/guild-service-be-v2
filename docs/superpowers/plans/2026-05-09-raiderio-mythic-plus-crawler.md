# Raider.io Mythic+ Run Crawler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a background crawler that fetches Mythic+ run data from raider.io's character profile endpoint (up to ~28 runs per character) and run-details endpoint (full 5-player rosters), storing them in the existing `dungeon_runs` / `dungeon_run_members` tables.

**Architecture:** Two-phase crawl: Phase 1 dispatches one job per tracked character (`mythic_plus_rating > 0`, ~751 characters) to fetch their raider.io profile and upsert runs. Phase 2 dispatches one job per unique new run to fetch full 5-player rosters via `/run-details`. Both phases share the existing `raiderio:requests` Redis throttle (900 req/min). A `RunTeamPersister` service is extracted from `SyncCharacterData` so both Blizzard sync and raider.io crawler share team persistence logic.

**Tech Stack:** Laravel 13, PHP 8.4, Horizon queues, Redis throttle, PostgreSQL

---

## Key Design Decisions

**Dungeon ID mapping:** Confirmed identical — `game_data_mythic_keystone_dungeons.id` (PK) == raider.io's `map_challenge_mode_id`. Evidence: `BackfillKeystoneDungeonIconsFromRaiderio` line 59 uses `GameDataMythicKeystoneDungeon::find($challengeModeId)`. No mapping needed.

**Deduplication:** Uses the same `updateOrCreate` match key as Blizzard sync: `(season, dungeon_id, completed_timestamp)`. A new `keystone_run_id` column (nullable bigint, unique) prevents raider.io-side duplicates when the same run appears in multiple characters' profiles. Raider.io's `completed_at` ISO string is converted to epoch ms to match Blizzard's format.

**Season ID:** Resolved via `BlizzardGameDataClient::getCurrentMythicPlusSeason()` (cached 24h, config override available). Passed from artisan command → jobs.

**Team data:** Phase 1 adds only the queried character as a member (no pruning). Phase 2 fetches full roster and does a full sync with pruning. `ShouldBeUnique` on `FetchRunRoster` keyed by `keystone_run_id` prevents duplicate dispatches.

---

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `database/migrations/..._add_raiderio_columns_to_dungeon_runs.php` | Create | Add `keystone_run_id`, `raiderio_score`, `raiderio_url` |
| `app/Models/DungeonRun.php` | Modify | Add new columns to `$fillable` and `$casts` |
| `app/Services/RaiderIO/DTO/RaiderIORun.php` | Create | Readonly DTO for mapped raider.io runs |
| `app/Services/RaiderIO/Mappers/RaiderIOMythicPlusMapper.php` | Create | Maps raider.io JSON → `RaiderIORun[]` DTOs + roster arrays |
| `app/Services/RunTeamPersister.php` | Create | Extracted team persistence (syncTeam + upsertMember) |
| `app/Blizzard/Jobs/SyncCharacterData.php` | Modify | Delegate `persistRunTeam` to `RunTeamPersister` |
| `app/Services/RaiderIO/RaiderIOClient.php` | Modify | Add `getCharacterMythicPlusRuns()` + `getRunDetails()` |
| `app/Services/RaiderIO/Jobs/CrawlCharacterRuns.php` | Create | Phase 1: fetch profile, upsert runs, dispatch roster jobs |
| `app/Services/RaiderIO/Jobs/FetchRunRoster.php` | Create | Phase 2: fetch run details, sync full team |
| `app/Console/Commands/CrawlMythicPlusRuns.php` | Create | Artisan command dispatching per-character jobs |
| `config/raiderio.php` | Modify | Add `crawl.enabled` |
| `config/horizon.php` | Modify | Add `raiderio-crawl` supervisor |
| `bootstrap/app.php` | Modify | Add scheduler entry |
| `tests/fixtures/raiderio/character-profile-mythicplus.json` | Create | Fixture for mapper tests |
| `tests/fixtures/raiderio/run-details.json` | Create | Fixture for run details mapper tests |
| `tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php` | Create | Mapper unit tests |
| `tests/Unit/Services/RunTeamPersisterTest.php` | Create | Extracted service unit tests |
| `tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php` | Create | Client method tests |
| `tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php` | Create | Phase 1 job integration tests |
| `tests/Feature/Services/RaiderIO/FetchRunRosterTest.php` | Create | Phase 2 job integration tests |

---

## Task 1: Migration + Model Update

**Files:**
- Create: `database/migrations/2026_05_09_100001_add_raiderio_columns_to_dungeon_runs.php`
- Modify: `app/Models/DungeonRun.php`

- [ ] **Step 1: Create migration**

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
        Schema::table('dungeon_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('keystone_run_id')->nullable()->unique()->after('id');
            $table->decimal('raiderio_score', 6, 1)->nullable()->after('affixes');
            $table->text('raiderio_url')->nullable()->after('raiderio_score');
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_runs', function (Blueprint $table) {
            $table->dropUnique(['keystone_run_id']);
            $table->dropColumn(['keystone_run_id', 'raiderio_score', 'raiderio_url']);
        });
    }
};
```

- [ ] **Step 2: Update DungeonRun model**

In `app/Models/DungeonRun.php`, add the three new columns to `$fillable`:

```php
protected $fillable = [
    'keystone_run_id',
    'season',
    'dungeon_id',
    'dungeon_name',
    'keystone_level',
    'duration',
    'completed_timestamp',
    'is_completed_on_time',
    'affixes',
    'raiderio_score',
    'raiderio_url',
];
```

Add to `$casts`:

```php
protected function casts(): array
{
    return [
        'affixes' => 'array',
        'is_completed_on_time' => 'boolean',
        'season' => 'integer',
        'keystone_level' => 'integer',
        'keystone_run_id' => 'integer',
        'raiderio_score' => 'decimal:1',
    ];
}
```

- [ ] **Step 3: Run migration**

```bash
docker compose exec app php artisan migrate
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_09_100001_add_raiderio_columns_to_dungeon_runs.php app/Models/DungeonRun.php
git commit -m "feat: add raiderio columns to dungeon_runs table"
```

---

## Task 2: RaiderIORun DTO

**Files:**
- Create: `app/Services/RaiderIO/DTO/RaiderIORun.php`

- [ ] **Step 1: Create DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class RaiderIORun
{
    public function __construct(
        public int $keystoneRunId,
        public int $season,
        public int $dungeonId,
        public string $dungeonName,
        public int $keystoneLevel,
        public int $duration,
        public int $completedTimestamp,
        public bool $isCompletedOnTime,
        public float $score,
        public string $url,
        public array $affixes,
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/RaiderIO/DTO/RaiderIORun.php
git commit -m "feat: add RaiderIORun DTO"
```

---

## Task 3: RaiderIOMythicPlusMapper (TDD)

**Files:**
- Create: `tests/fixtures/raiderio/character-profile-mythicplus.json`
- Create: `tests/fixtures/raiderio/run-details.json`
- Create: `tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php`
- Create: `app/Services/RaiderIO/Mappers/RaiderIOMythicPlusMapper.php`

- [ ] **Step 1: Create character profile fixture**

File: `tests/fixtures/raiderio/character-profile-mythicplus.json`

```json
{
  "name": "Testchar",
  "realm": "the-maelstrom",
  "region": "eu",
  "mythic_plus_recent_runs": [
    {
      "dungeon": "Seat of the Triumvirate",
      "short_name": "SEAT",
      "mythic_level": 16,
      "completed_at": "2026-05-05T18:28:26.000Z",
      "clear_time_ms": 1814558,
      "par_time_ms": 2040999,
      "num_keystone_upgrades": 1,
      "map_challenge_mode_id": 239,
      "score": 429.2,
      "affixes": [{ "id": 9, "name": "Tyrannical" }],
      "url": "https://raider.io/mythic-plus-runs/season-mn-1/21957615-16-seat-of-the-triumvirate",
      "spec": { "id": 259, "name": "Assassination" },
      "role": "dps"
    },
    {
      "dungeon": "Darkflame Cleft",
      "short_name": "DFC",
      "mythic_level": 14,
      "completed_at": "2026-05-04T10:00:00.000Z",
      "clear_time_ms": 1500000,
      "par_time_ms": 1800000,
      "num_keystone_upgrades": 2,
      "map_challenge_mode_id": 241,
      "score": 380.5,
      "affixes": [{ "id": 10, "name": "Fortified" }],
      "url": "https://raider.io/mythic-plus-runs/season-mn-1/21900000-14-darkflame-cleft",
      "spec": { "id": 259, "name": "Assassination" },
      "role": "dps"
    }
  ],
  "mythic_plus_best_runs": [
    {
      "dungeon": "Seat of the Triumvirate",
      "short_name": "SEAT",
      "mythic_level": 16,
      "completed_at": "2026-05-05T18:28:26.000Z",
      "clear_time_ms": 1814558,
      "par_time_ms": 2040999,
      "num_keystone_upgrades": 1,
      "map_challenge_mode_id": 239,
      "score": 429.2,
      "affixes": [{ "id": 9, "name": "Tyrannical" }],
      "url": "https://raider.io/mythic-plus-runs/season-mn-1/21957615-16-seat-of-the-triumvirate",
      "spec": { "id": 259, "name": "Assassination" },
      "role": "dps"
    }
  ],
  "mythic_plus_highest_level_runs": [
    {
      "dungeon": "Seat of the Triumvirate",
      "short_name": "SEAT",
      "mythic_level": 16,
      "completed_at": "2026-05-05T18:28:26.000Z",
      "clear_time_ms": 1814558,
      "par_time_ms": 2040999,
      "num_keystone_upgrades": 1,
      "map_challenge_mode_id": 239,
      "score": 429.2,
      "affixes": [{ "id": 9, "name": "Tyrannical" }],
      "url": "https://raider.io/mythic-plus-runs/season-mn-1/21957615-16-seat-of-the-triumvirate",
      "spec": { "id": 259, "name": "Assassination" },
      "role": "dps"
    },
    {
      "dungeon": "The Rookery",
      "short_name": "ROOK",
      "mythic_level": 15,
      "completed_at": "2026-05-03T14:30:00.000Z",
      "clear_time_ms": 1650000,
      "par_time_ms": 1920000,
      "num_keystone_upgrades": 1,
      "map_challenge_mode_id": 245,
      "score": 400.0,
      "affixes": [{ "id": 9, "name": "Tyrannical" }],
      "url": "https://raider.io/mythic-plus-runs/season-mn-1/21850000-15-the-rookery",
      "spec": { "id": 259, "name": "Assassination" },
      "role": "dps"
    }
  ]
}
```

This fixture has: 2 recent + 1 best + 2 highest = 5 entries, but the SEAT run (id 21957615) appears in all three lists → 3 unique runs after dedup.

- [ ] **Step 2: Create run-details fixture**

File: `tests/fixtures/raiderio/run-details.json`

```json
{
  "keystone_run_id": 21957615,
  "season": "season-mn-1",
  "mythic_level": 16,
  "clear_time_ms": 1814558,
  "completed_at": "2026-05-05T18:28:26.000Z",
  "par_time_ms": 2040999,
  "num_keystone_upgrades": 1,
  "map_challenge_mode_id": 239,
  "dungeon": {
    "name": "Seat of the Triumvirate",
    "short_name": "SEAT"
  },
  "score": 429.2,
  "roster": [
    {
      "character": {
        "name": "Testchar",
        "realm": { "slug": "the-maelstrom", "name": "The Maelstrom" },
        "region": { "slug": "eu" },
        "spec": { "id": 259, "name": "Assassination", "slug": "assassination" },
        "class": { "id": 4, "name": "Rogue", "slug": "rogue" }
      },
      "role": "dps",
      "items": { "item_level_equipped": 489 }
    },
    {
      "character": {
        "name": "Healer",
        "realm": { "slug": "tarren-mill", "name": "Tarren Mill" },
        "region": { "slug": "eu" },
        "spec": { "id": 65, "name": "Holy", "slug": "holy" },
        "class": { "id": 2, "name": "Paladin", "slug": "paladin" }
      },
      "role": "healer",
      "items": { "item_level_equipped": 495 }
    },
    {
      "character": {
        "name": "Tank",
        "realm": { "slug": "kazzak", "name": "Kazzak" },
        "region": { "slug": "eu" },
        "spec": { "id": 73, "name": "Protection", "slug": "protection" },
        "class": { "id": 1, "name": "Warrior", "slug": "warrior" }
      },
      "role": "tank",
      "items": { "item_level_equipped": 492 }
    },
    {
      "character": {
        "name": "Dps2",
        "realm": { "slug": "draenor", "name": "Draenor" },
        "region": { "slug": "eu" },
        "spec": { "id": 62, "name": "Arcane", "slug": "arcane" },
        "class": { "id": 8, "name": "Mage", "slug": "mage" }
      },
      "role": "dps",
      "items": { "item_level_equipped": 487 }
    },
    {
      "character": {
        "name": "Dps3",
        "realm": { "slug": "stormscale", "name": "Stormscale" },
        "region": { "slug": "eu" },
        "spec": { "id": 577, "name": "Havoc", "slug": "havoc" },
        "class": { "id": 12, "name": "Demon Hunter", "slug": "demon-hunter" }
      },
      "role": "dps",
      "items": { "item_level_equipped": 491 }
    }
  ]
}
```

- [ ] **Step 3: Write failing mapper tests**

File: `tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\RaiderIORun;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use Carbon\Carbon;
use Tests\TestCase;

class RaiderIOMythicPlusMapperTest extends TestCase
{
    private RaiderIOMythicPlusMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new RaiderIOMythicPlusMapper;
    }

    public function test_maps_character_profile_runs_and_deduplicates_across_lists(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);

        // Fixture has 5 entries across 3 lists, but SEAT (id 21957615) appears 3 times → 3 unique
        $this->assertCount(3, $runs);
        $this->assertContainsOnlyInstancesOf(RaiderIORun::class, $runs);

        // Verify dedup kept one instance per keystone_run_id
        $ids = array_map(fn (RaiderIORun $r) => $r->keystoneRunId, $runs);
        $this->assertCount(3, array_unique($ids));
    }

    public function test_maps_run_fields_correctly(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $seat = collect($runs)->first(fn (RaiderIORun $r) => $r->keystoneRunId === 21957615);

        $this->assertNotNull($seat);
        $this->assertSame(13, $seat->season);
        $this->assertSame(239, $seat->dungeonId);
        $this->assertSame('Seat of the Triumvirate', $seat->dungeonName);
        $this->assertSame(16, $seat->keystoneLevel);
        $this->assertSame(1814558, $seat->duration);
        $this->assertTrue($seat->isCompletedOnTime);
        $this->assertSame(429.2, $seat->score);
        $this->assertStringContainsString('21957615', $seat->url);
    }

    public function test_converts_completed_at_to_epoch_ms(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $seat = collect($runs)->first(fn (RaiderIORun $r) => $r->keystoneRunId === 21957615);

        $expected = Carbon::parse('2026-05-05T18:28:26.000Z')->getTimestampMs();
        $this->assertSame($expected, $seat->completedTimestamp);
    }

    public function test_maps_affixes(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $seat = collect($runs)->first(fn (RaiderIORun $r) => $r->keystoneRunId === 21957615);

        $this->assertSame([['id' => 9, 'name' => 'Tyrannical']], $seat->affixes);
    }

    public function test_depleted_run_sets_is_completed_on_time_false(): void
    {
        $data = [
            'mythic_plus_recent_runs' => [[
                'dungeon' => 'Test',
                'short_name' => 'TEST',
                'mythic_level' => 10,
                'completed_at' => '2026-05-01T12:00:00.000Z',
                'clear_time_ms' => 2000000,
                'par_time_ms' => 1800000,
                'num_keystone_upgrades' => 0,
                'map_challenge_mode_id' => 100,
                'score' => 100.0,
                'affixes' => [],
                'url' => 'https://raider.io/mythic-plus-runs/season-mn-1/99999-10-test',
            ]],
            'mythic_plus_best_runs' => [],
            'mythic_plus_highest_level_runs' => [],
        ];

        $runs = $this->mapper->mapCharacterProfileRuns($data, 13);

        $this->assertCount(1, $runs);
        $this->assertFalse($runs[0]->isCompletedOnTime);
    }

    public function test_returns_empty_array_for_missing_run_lists(): void
    {
        $runs = $this->mapper->mapCharacterProfileRuns([], 13);
        $this->assertSame([], $runs);
    }

    public function test_extracts_keystone_run_id_from_url(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $ids = array_map(fn (RaiderIORun $r) => $r->keystoneRunId, $runs);

        $this->assertContains(21957615, $ids);
        $this->assertContains(21900000, $ids);
        $this->assertContains(21850000, $ids);
    }

    public function test_maps_run_details_roster(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );

        $team = $this->mapper->mapRunDetailsRoster($fixture);

        $this->assertCount(5, $team);
        $this->assertSame('Testchar', $team[0]['name']);
        $this->assertSame('the-maelstrom', $team[0]['realm']);
        $this->assertSame('The Maelstrom', $team[0]['realm_name']);
        $this->assertSame(259, $team[0]['specialization_id']);
        $this->assertSame('Assassination', $team[0]['specialization']);
        $this->assertSame(489, $team[0]['equipped_item_level']);
    }

    public function test_run_details_roster_handles_missing_items(): void
    {
        $data = [
            'roster' => [[
                'character' => [
                    'name' => 'NoItems',
                    'realm' => ['slug' => 'kazzak', 'name' => 'Kazzak'],
                    'region' => ['slug' => 'eu'],
                    'spec' => ['id' => 73, 'name' => 'Protection'],
                ],
                'role' => 'tank',
            ]],
        ];

        $team = $this->mapper->mapRunDetailsRoster($data);

        $this->assertCount(1, $team);
        $this->assertNull($team[0]['equipped_item_level']);
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php
```

Expected: FAIL — class `RaiderIOMythicPlusMapper` not found.

- [ ] **Step 5: Implement mapper**

File: `app/Services/RaiderIO/Mappers/RaiderIOMythicPlusMapper.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Mappers;

use App\Services\RaiderIO\DTO\RaiderIORun;
use Carbon\Carbon;

class RaiderIOMythicPlusMapper
{
    /**
     * @return RaiderIORun[]
     */
    public function mapCharacterProfileRuns(array $profileData, int $season): array
    {
        $seen = [];
        $runs = [];

        foreach (['mythic_plus_recent_runs', 'mythic_plus_best_runs', 'mythic_plus_highest_level_runs'] as $field) {
            foreach ($profileData[$field] ?? [] as $run) {
                $keystoneRunId = $this->extractKeystoneRunId($run['url'] ?? '');
                if ($keystoneRunId === null || isset($seen[$keystoneRunId])) {
                    continue;
                }
                $seen[$keystoneRunId] = true;

                $runs[] = new RaiderIORun(
                    keystoneRunId: $keystoneRunId,
                    season: $season,
                    dungeonId: (int) ($run['map_challenge_mode_id'] ?? 0),
                    dungeonName: $run['dungeon'] ?? 'Unknown',
                    keystoneLevel: (int) ($run['mythic_level'] ?? 0),
                    duration: (int) ($run['clear_time_ms'] ?? 0),
                    completedTimestamp: Carbon::parse($run['completed_at'])->getTimestampMs(),
                    isCompletedOnTime: ($run['num_keystone_upgrades'] ?? 0) > 0,
                    score: (float) ($run['score'] ?? 0),
                    url: $run['url'] ?? '',
                    affixes: array_map(
                        fn (array $a) => ['id' => (int) ($a['id'] ?? 0), 'name' => $a['name'] ?? 'Unknown'],
                        $run['affixes'] ?? [],
                    ),
                );
            }
        }

        return $runs;
    }

    /**
     * @return array<int, array{name: string, realm: string, realm_name: ?string, specialization_id: ?int, specialization: ?string, equipped_item_level: ?int}>
     */
    public function mapRunDetailsRoster(array $detailsData): array
    {
        $team = [];

        foreach ($detailsData['roster'] ?? [] as $entry) {
            $character = $entry['character'] ?? [];
            $team[] = [
                'name' => $character['name'] ?? 'Unknown',
                'realm' => $character['realm']['slug'] ?? 'unknown',
                'realm_name' => $character['realm']['name'] ?? null,
                'specialization_id' => isset($character['spec']['id']) ? (int) $character['spec']['id'] : null,
                'specialization' => $character['spec']['name'] ?? null,
                'equipped_item_level' => isset($entry['items']['item_level_equipped'])
                    ? (int) $entry['items']['item_level_equipped']
                    : null,
            ];
        }

        return $team;
    }

    private function extractKeystoneRunId(string $url): ?int
    {
        if (preg_match('/mythic-plus-runs\/[^\/]+\/(\d+)/', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php
```

Expected: All 8 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/fixtures/raiderio/character-profile-mythicplus.json tests/fixtures/raiderio/run-details.json tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php app/Services/RaiderIO/Mappers/RaiderIOMythicPlusMapper.php app/Services/RaiderIO/DTO/RaiderIORun.php
git commit -m "feat: add RaiderIOMythicPlusMapper with TDD tests"
```

---

## Task 4: Extract RunTeamPersister Service (TDD)

**Files:**
- Create: `app/Services/RunTeamPersister.php`
- Create: `tests/Unit/Services/RunTeamPersisterTest.php`
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`

- [ ] **Step 1: Write failing tests for RunTeamPersister**

These mirror the existing `SyncMythicPlusTeamPivotTest` assertions but target the extracted service. Check `tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php` for the exact assertions and update any that depend on `SyncCharacterData::persistRunTeam()` directly.

File: `tests/Unit/Services/RunTeamPersisterTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Services\RunTeamPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RunTeamPersisterTest extends TestCase
{
    use RefreshDatabase;

    private RunTeamPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persister = new RunTeamPersister;
    }

    public function test_sync_team_upserts_all_members(): void
    {
        $run = DungeonRun::factory()->create();
        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => 'Tarren Mill', 'specialization_id' => 259, 'specialization' => 'Assassination', 'equipped_item_level' => 489],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => 'Kazzak', 'specialization_id' => 65, 'specialization' => 'Holy', 'equipped_item_level' => 495],
        ];

        $this->persister->syncTeam($run, $team, 'eu');

        $this->assertSame(2, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
    }

    public function test_sync_team_resolves_character_id_for_known_characters(): void
    {
        $character = Character::factory()->create([
            'name' => 'alice',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
        ]);
        $run = DungeonRun::factory()->create();
        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Assassination', 'equipped_item_level' => 489],
        ];

        $this->persister->syncTeam($run, $team, 'eu');

        $member = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
        $this->assertSame($character->id, $member->character_id);
    }

    public function test_sync_team_prunes_stale_members(): void
    {
        $run = DungeonRun::factory()->create();
        $oldTeam = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Holy', 'equipped_item_level' => 490],
        ];
        $this->persister->syncTeam($run, $oldTeam, 'eu');
        $this->assertSame(2, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());

        $newTeam = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
        ];
        $this->persister->syncTeam($run, $newTeam, 'eu');
        $this->assertSame(1, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
    }

    public function test_upsert_member_adds_single_member_without_pruning(): void
    {
        $run = DungeonRun::factory()->create();

        // Insert two members via syncTeam
        $team = [
            ['name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Frost', 'equipped_item_level' => 480],
            ['name' => 'Bob', 'realm' => 'kazzak', 'realm_name' => null, 'specialization_id' => null, 'specialization' => 'Holy', 'equipped_item_level' => 490],
        ];
        $this->persister->syncTeam($run, $team, 'eu');

        // upsertMember adds a third without removing Alice/Bob
        $this->persister->upsertMember($run, [
            'name' => 'Cara', 'realm' => 'draenor', 'realm_name' => 'Draenor',
            'specialization_id' => 73, 'specialization' => 'Protection', 'equipped_item_level' => 492,
        ], 'eu');

        $this->assertSame(3, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
    }

    public function test_upsert_member_updates_existing_member(): void
    {
        $run = DungeonRun::factory()->create();

        $this->persister->upsertMember($run, [
            'name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => null,
            'specialization_id' => 259, 'specialization' => 'Assassination', 'equipped_item_level' => 480,
        ], 'eu');

        $this->persister->upsertMember($run, [
            'name' => 'Alice', 'realm' => 'tarren-mill', 'realm_name' => 'Tarren Mill',
            'specialization_id' => 260, 'specialization' => 'Subtlety', 'equipped_item_level' => 495,
        ], 'eu');

        $this->assertSame(1, DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->count());
        $member = DB::table('dungeon_run_members')->where('dungeon_run_id', $run->id)->first();
        $this->assertSame(495, $member->equipped_item_level);
        $this->assertSame('Subtlety', $member->spec_name);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/RunTeamPersisterTest.php
```

Expected: FAIL — class `RunTeamPersister` not found.

- [ ] **Step 3: Implement RunTeamPersister**

File: `app/Services/RunTeamPersister.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use App\Models\DungeonRun;
use Illuminate\Support\Facades\DB;

class RunTeamPersister
{
    /**
     * @param  array<int, array{name: string, realm: string, realm_name?: ?string, specialization_id?: ?int, specialization: ?string, equipped_item_level: ?int}>  $team
     */
    public function syncTeam(DungeonRun $run, array $team, string $region): void
    {
        $now = now();
        $keep = [];

        foreach ($team as $member) {
            $resolvedId = Character::query()
                ->where('name', strtolower($member['name']))
                ->where('realm', $member['realm'])
                ->where('region', $region)
                ->where('game_version', 'retail')
                ->value('id');

            DB::table('dungeon_run_members')->updateOrInsert(
                [
                    'dungeon_run_id' => $run->id,
                    'character_name' => $member['name'],
                    'character_realm' => $member['realm'],
                    'character_region' => $region,
                ],
                [
                    'character_id' => $resolvedId,
                    'display_realm' => $member['realm_name'] ?? null,
                    'spec_id' => $member['specialization_id'] ?? null,
                    'spec_name' => $member['specialization'],
                    'equipped_item_level' => $member['equipped_item_level'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $keep[] = [
                'name' => $member['name'],
                'realm' => $member['realm'],
                'region' => $region,
            ];
        }

        $existing = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->get(['id', 'character_name', 'character_realm', 'character_region']);

        $keepKey = fn (string $n, string $r, string $reg) => "{$n}|{$r}|{$reg}";
        $keepSet = collect($keep)
            ->mapWithKeys(fn ($k) => [$keepKey($k['name'], $k['realm'], $k['region']) => true])
            ->all();

        $toDelete = $existing
            ->reject(fn ($row) => isset($keepSet[$keepKey($row->character_name, $row->character_realm, $row->character_region)]))
            ->pluck('id')
            ->all();

        if ($toDelete !== []) {
            DB::table('dungeon_run_members')->whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * @param  array{name: string, realm: string, realm_name?: ?string, specialization_id?: ?int, specialization: ?string, equipped_item_level: ?int}  $member
     */
    public function upsertMember(DungeonRun $run, array $member, string $region): void
    {
        $resolvedId = Character::query()
            ->where('name', strtolower($member['name']))
            ->where('realm', $member['realm'])
            ->where('region', $region)
            ->where('game_version', 'retail')
            ->value('id');

        DB::table('dungeon_run_members')->updateOrInsert(
            [
                'dungeon_run_id' => $run->id,
                'character_name' => $member['name'],
                'character_realm' => $member['realm'],
                'character_region' => $region,
            ],
            [
                'character_id' => $resolvedId,
                'display_realm' => $member['realm_name'] ?? null,
                'spec_id' => $member['specialization_id'] ?? null,
                'spec_name' => $member['specialization'],
                'equipped_item_level' => $member['equipped_item_level'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/RunTeamPersisterTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Delegate SyncCharacterData::persistRunTeam to RunTeamPersister**

In `app/Blizzard/Jobs/SyncCharacterData.php`, replace the `persistRunTeam` method body (lines 369–424) with delegation:

```php
public function persistRunTeam(DungeonRun $run, array $team): void
{
    app(RunTeamPersister::class)->syncTeam($run, $team, $this->region);
}
```

Add the import at the top of the file:

```php
use App\Services\RunTeamPersister;
```

- [ ] **Step 6: Run existing SyncMythicPlusTeamPivotTest to verify no regression**

```bash
./vendor/bin/phpunit tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
```

Expected: All existing tests still PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/RunTeamPersister.php tests/Unit/Services/RunTeamPersisterTest.php app/Blizzard/Jobs/SyncCharacterData.php
git commit -m "refactor: extract RunTeamPersister from SyncCharacterData"
```

---

## Task 5: RaiderIOClient New Methods (TDD)

**Files:**
- Create: `tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php`
- Modify: `app/Services/RaiderIO/RaiderIOClient.php`

- [ ] **Step 1: Write failing tests**

File: `tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientCrawlTest extends TestCase
{
    public function test_get_character_mythic_plus_runs_returns_profile_data(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        Http::fake([
            'raider.io/api/v1/characters/profile*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);
        $result = $client->getCharacterMythicPlusRuns('eu', 'the-maelstrom', 'testchar');

        $this->assertArrayHasKey('mythic_plus_recent_runs', $result);
        $this->assertArrayHasKey('mythic_plus_best_runs', $result);
        $this->assertArrayHasKey('mythic_plus_highest_level_runs', $result);
        $this->assertCount(2, $result['mythic_plus_recent_runs']);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, 'characters/profile')
                && str_contains($url, 'region=eu')
                && str_contains($url, 'realm=the-maelstrom')
                && str_contains($url, 'name=testchar')
                && str_contains($url, 'mythic_plus_recent_runs')
                && str_contains($url, 'mythic_plus_best_runs')
                && str_contains($url, 'mythic_plus_highest_level_runs');
        });
    }

    public function test_get_run_details_returns_run_data(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );

        Http::fake([
            'raider.io/api/v1/mythic-plus/run-details*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);
        $result = $client->getRunDetails('season-mn-1', 21957615);

        $this->assertSame(21957615, $result['keystone_run_id']);
        $this->assertCount(5, $result['roster']);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, 'mythic-plus/run-details')
                && str_contains($url, 'season=season-mn-1')
                && str_contains($url, 'id=21957615');
        });
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php
```

Expected: FAIL — methods not found.

- [ ] **Step 3: Add methods to RaiderIOClient**

In `app/Services/RaiderIO/RaiderIOClient.php`, add two new public methods after the `mythicPlusStaticData` method (around line 147):

```php
    /**
     * @return array<string, mixed>
     */
    public function getCharacterMythicPlusRuns(string $region, string $realm, string $name): array
    {
        $response = $this->get('/characters/profile', [
            'region' => $region,
            'realm' => $realm,
            'name' => $name,
            'fields' => 'mythic_plus_recent_runs,mythic_plus_best_runs,mythic_plus_highest_level_runs',
        ]);

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRunDetails(string $season, int $keystoneRunId): array
    {
        $response = $this->get('/mythic-plus/run-details', [
            'season' => $season,
            'id' => $keystoneRunId,
        ]);

        return $response->json() ?? [];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php
```

Expected: All 2 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php app/Services/RaiderIO/RaiderIOClient.php
git commit -m "feat: add getCharacterMythicPlusRuns and getRunDetails to RaiderIOClient"
```

---

## Task 6: CrawlCharacterRuns Job — Phase 1 (TDD)

**Files:**
- Create: `tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php`
- Create: `app/Services/RaiderIO/Jobs/CrawlCharacterRuns.php`

- [ ] **Step 1: Write failing tests**

File: `tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RaiderIO;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Jobs\CrawlCharacterRuns;
use App\Services\RaiderIO\Jobs\FetchRunRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlCharacterRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_dungeon_runs_from_raiderio_profile(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
        Queue::fake([FetchRunRoster::class]);

        $job = new CrawlCharacterRuns('eu', 'the-maelstrom', 'testchar', 13);
        $job->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        // Fixture has 3 unique runs after dedup
        $this->assertSame(3, DungeonRun::count());
    }

    public function test_sets_raiderio_columns_on_dungeon_runs(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
        Queue::fake([FetchRunRoster::class]);

        $job = new CrawlCharacterRuns('eu', 'the-maelstrom', 'testchar', 13);
        $job->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        $seat = DungeonRun::where('keystone_run_id', 21957615)->first();
        $this->assertNotNull($seat);
        $this->assertSame(239, $seat->dungeon_id);
        $this->assertSame(16, $seat->keystone_level);
        $this->assertSame('429.2', $seat->raiderio_score);
        $this->assertStringContainsString('21957615', $seat->raiderio_url);
    }

    public function test_adds_queried_character_as_member(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
        Queue::fake([FetchRunRoster::class]);

        $job = new CrawlCharacterRuns('eu', 'the-maelstrom', 'testchar', 13);
        $job->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        $seat = DungeonRun::where('keystone_run_id', 21957615)->first();
        $members = \Illuminate\Support\Facades\DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $seat->id)
            ->get();

        $this->assertSame(1, $members->count());
        $this->assertSame('testchar', $members->first()->character_name);
        $this->assertSame('the-maelstrom', $members->first()->character_realm);
        $this->assertSame('eu', $members->first()->character_region);
    }

    public function test_dispatches_fetch_run_roster_for_new_runs(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
        Queue::fake([FetchRunRoster::class]);

        $job = new CrawlCharacterRuns('eu', 'the-maelstrom', 'testchar', 13);
        $job->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        Queue::assertPushed(FetchRunRoster::class, 3);
    }

    public function test_does_not_dispatch_roster_for_runs_with_full_team(): void
    {
        // Pre-create a run with 5 members
        $run = DungeonRun::factory()->create([
            'keystone_run_id' => 21957615,
            'season' => 13,
            'dungeon_id' => 239,
            'completed_timestamp' => \Carbon\Carbon::parse('2026-05-05T18:28:26.000Z')->getTimestampMs(),
        ]);
        for ($i = 0; $i < 5; $i++) {
            \Illuminate\Support\Facades\DB::table('dungeon_run_members')->insert([
                'dungeon_run_id' => $run->id,
                'character_name' => "player{$i}",
                'character_realm' => 'tarren-mill',
                'character_region' => 'eu',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
        Queue::fake([FetchRunRoster::class]);

        $job = new CrawlCharacterRuns('eu', 'the-maelstrom', 'testchar', 13);
        $job->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        // Only 2 roster dispatches (DFC + ROOK are new; SEAT already has 5 members)
        Queue::assertPushed(FetchRunRoster::class, 2);
    }

    public function test_deduplicates_across_characters(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/characters/profile*' => Http::response($fixture, 200)]);
        Queue::fake([FetchRunRoster::class]);

        // First character processes runs
        $job1 = new CrawlCharacterRuns('eu', 'the-maelstrom', 'testchar', 13);
        $job1->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        // Second character processes same runs — should not create duplicates
        $job2 = new CrawlCharacterRuns('eu', 'tarren-mill', 'anotherchar', 13);
        $job2->handle(app(\App\Services\RaiderIO\RaiderIOClient::class), new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper, app(\App\Services\RunTeamPersister::class));

        $this->assertSame(3, DungeonRun::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php
```

Expected: FAIL — class `CrawlCharacterRuns` not found.

- [ ] **Step 3: Implement CrawlCharacterRuns job**

File: `app/Services/RaiderIO/Jobs/CrawlCharacterRuns.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Jobs;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RunTeamPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrawlCharacterRuns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $region,
        public readonly string $realm,
        public readonly string $name,
        public readonly int $season,
    ) {
        $this->onQueue('raiderio-crawl');
    }

    public function uniqueId(): string
    {
        return "raiderio-crawl:{$this->region}:{$this->realm}:{$this->name}";
    }

    public function handle(
        RaiderIOClient $client,
        RaiderIOMythicPlusMapper $mapper,
        RunTeamPersister $persister,
    ): void {
        $profileData = $client->getCharacterMythicPlusRuns($this->region, $this->realm, $this->name);
        $runs = $mapper->mapCharacterProfileRuns($profileData, $this->season);

        foreach ($runs as $run) {
            $dungeonRun = DungeonRun::updateOrCreate(
                [
                    'season' => $run->season,
                    'dungeon_id' => $run->dungeonId,
                    'completed_timestamp' => $run->completedTimestamp,
                ],
                [
                    'keystone_run_id' => $run->keystoneRunId,
                    'dungeon_name' => $run->dungeonName,
                    'keystone_level' => $run->keystoneLevel,
                    'duration' => $run->duration,
                    'is_completed_on_time' => $run->isCompletedOnTime,
                    'affixes' => $run->affixes,
                    'raiderio_score' => $run->score,
                    'raiderio_url' => $run->url,
                ],
            );

            $this->addQueriedCharacterAsMember($dungeonRun, $profileData, $persister);
            $this->dispatchRosterFetchIfNeeded($dungeonRun, $run->keystoneRunId);
        }
    }

    private function addQueriedCharacterAsMember(DungeonRun $run, array $profileData, RunTeamPersister $persister): void
    {
        $recentRun = ($profileData['mythic_plus_recent_runs'] ?? $profileData['mythic_plus_best_runs'] ?? $profileData['mythic_plus_highest_level_runs'] ?? [])[0] ?? null;

        $persister->upsertMember($run, [
            'name' => $this->name,
            'realm' => $this->realm,
            'realm_name' => null,
            'specialization_id' => $recentRun['spec']['id'] ?? null,
            'specialization' => $recentRun['spec']['name'] ?? null,
            'equipped_item_level' => null,
        ], $this->region);
    }

    private function dispatchRosterFetchIfNeeded(DungeonRun $run, int $keystoneRunId): void
    {
        $memberCount = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->count();

        if ($memberCount < 5) {
            FetchRunRoster::dispatch(
                $keystoneRunId,
                (string) config('raiderio.season'),
                $this->region,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CrawlCharacterRuns failed', [
            'region' => $this->region,
            'realm' => $this->realm,
            'name' => $this->name,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Create a stub FetchRunRoster class so tests can reference it**

File: `app/Services/RaiderIO/Jobs/FetchRunRoster.php` (stub — full implementation in Task 7)

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchRunRoster implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $keystoneRunId,
        public readonly string $season,
        public readonly string $region,
    ) {
        $this->onQueue('raiderio-crawl');
    }

    public function uniqueId(): string
    {
        return "raiderio-roster:{$this->keystoneRunId}";
    }

    public function handle(): void
    {
        // Implemented in Task 7
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php
```

Expected: All 6 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/RaiderIO/Jobs/CrawlCharacterRuns.php app/Services/RaiderIO/Jobs/FetchRunRoster.php tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php
git commit -m "feat: add CrawlCharacterRuns job (phase 1 crawl)"
```

---

## Task 7: FetchRunRoster Job — Phase 2 (TDD)

**Files:**
- Create: `tests/Feature/Services/RaiderIO/FetchRunRosterTest.php`
- Modify: `app/Services/RaiderIO/Jobs/FetchRunRoster.php`

- [ ] **Step 1: Write failing tests**

File: `tests/Feature/Services/RaiderIO/FetchRunRosterTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RaiderIO;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Jobs\FetchRunRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchRunRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_full_roster_from_run_details(): void
    {
        $run = DungeonRun::factory()->create(['keystone_run_id' => 21957615]);
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/mythic-plus/run-details*' => Http::response($fixture, 200)]);

        $job = new FetchRunRoster(21957615, 'season-mn-1', 'eu');
        $job->handle(
            app(\App\Services\RaiderIO\RaiderIOClient::class),
            new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper,
            app(\App\Services\RunTeamPersister::class),
        );

        $members = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->get();

        $this->assertSame(5, $members->count());
        $names = $members->pluck('character_name')->sort()->values()->all();
        $this->assertSame(['Dps2', 'Dps3', 'Healer', 'Tank', 'Testchar'], $names);
    }

    public function test_sets_spec_and_ilvl_on_members(): void
    {
        $run = DungeonRun::factory()->create(['keystone_run_id' => 21957615]);
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );
        Http::fake(['raider.io/api/v1/mythic-plus/run-details*' => Http::response($fixture, 200)]);

        $job = new FetchRunRoster(21957615, 'season-mn-1', 'eu');
        $job->handle(
            app(\App\Services\RaiderIO\RaiderIOClient::class),
            new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper,
            app(\App\Services\RunTeamPersister::class),
        );

        $testchar = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->where('character_name', 'Testchar')
            ->first();

        $this->assertSame(259, $testchar->spec_id);
        $this->assertSame('Assassination', $testchar->spec_name);
        $this->assertSame(489, $testchar->equipped_item_level);
        $this->assertSame('The Maelstrom', $testchar->display_realm);
    }

    public function test_bails_if_run_not_found(): void
    {
        Http::fake(['raider.io/api/v1/mythic-plus/run-details*' => Http::response([], 200)]);

        $job = new FetchRunRoster(99999, 'season-mn-1', 'eu');
        $job->handle(
            app(\App\Services\RaiderIO\RaiderIOClient::class),
            new \App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper,
            app(\App\Services\RunTeamPersister::class),
        );

        // No crash, no members created
        $this->assertSame(0, DB::table('dungeon_run_members')->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Feature/Services/RaiderIO/FetchRunRosterTest.php
```

Expected: FAIL — `FetchRunRoster::handle()` is a no-op stub.

- [ ] **Step 3: Implement FetchRunRoster**

Replace the stub in `app/Services/RaiderIO/Jobs/FetchRunRoster.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\Jobs;

use App\Models\DungeonRun;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use App\Services\RaiderIO\RaiderIOClient;
use App\Services\RunTeamPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchRunRoster implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $keystoneRunId,
        public readonly string $season,
        public readonly string $region,
    ) {
        $this->onQueue('raiderio-crawl');
    }

    public function uniqueId(): string
    {
        return "raiderio-roster:{$this->keystoneRunId}";
    }

    public function handle(
        RaiderIOClient $client,
        RaiderIOMythicPlusMapper $mapper,
        RunTeamPersister $persister,
    ): void {
        $run = DungeonRun::where('keystone_run_id', $this->keystoneRunId)->first();
        if ($run === null) {
            return;
        }

        $detailsData = $client->getRunDetails($this->season, $this->keystoneRunId);
        $team = $mapper->mapRunDetailsRoster($detailsData);

        if ($team === []) {
            return;
        }

        $persister->syncTeam($run, $team, $this->region);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FetchRunRoster failed', [
            'keystone_run_id' => $this->keystoneRunId,
            'season' => $this->season,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Feature/Services/RaiderIO/FetchRunRosterTest.php
```

Expected: All 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RaiderIO/Jobs/FetchRunRoster.php tests/Feature/Services/RaiderIO/FetchRunRosterTest.php
git commit -m "feat: add FetchRunRoster job (phase 2 roster crawl)"
```

---

## Task 8: Artisan Command + Config + Horizon + Scheduler

**Files:**
- Create: `app/Console/Commands/CrawlMythicPlusRuns.php`
- Modify: `config/raiderio.php`
- Modify: `config/horizon.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Add crawl config to raiderio.php**

In `config/raiderio.php`, add at the end of the array (before the closing `]`):

```php
    'crawl' => [
        'enabled' => (bool) env('RAIDERIO_CRAWL_ENABLED', false),
    ],
```

- [ ] **Step 2: Create artisan command**

File: `app/Console/Commands/CrawlMythicPlusRuns.php`

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\Character;
use App\Services\RaiderIO\Jobs\CrawlCharacterRuns;
use Illuminate\Console\Command;

class CrawlMythicPlusRuns extends Command
{
    protected $signature = 'raiderio:crawl-runs
        {--region= : Limit to a specific region}
        {--limit= : Max characters to dispatch}
        {--dry-run : Report counts without dispatching}';

    protected $description = 'Crawl raider.io for Mythic+ run data for tracked characters';

    public function handle(BlizzardGameDataClient $gameData): int
    {
        if (! config('raiderio.crawl.enabled')) {
            $this->warn('Raider.io crawl is disabled (RAIDERIO_CRAWL_ENABLED=false).');

            return self::SUCCESS;
        }

        $season = $gameData->getCurrentMythicPlusSeason();

        $query = Character::query()
            ->where('mythic_plus_rating', '>', 0)
            ->where('game_version', 'retail');

        if ($region = $this->option('region')) {
            $query->where('region', $region);
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $characters = $query->get(['region', 'realm', 'name']);

        if ($this->option('dry-run')) {
            $this->info("Dry run: would dispatch {$characters->count()} CrawlCharacterRuns jobs (season {$season}).");

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($characters as $character) {
            CrawlCharacterRuns::dispatch(
                $character->region,
                $character->realm,
                $character->name,
                $season,
            );
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} CrawlCharacterRuns jobs (season {$season}).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Add Horizon supervisor**

In `config/horizon.php`, add a new supervisor entry under both `production` and `local` environments. Add it after the `'default-worker'` supervisor in each environment block:

```php
            'raiderio-crawl' => [
                'connection' => 'redis',
                'queue' => ['raiderio-crawl'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 5,
                'timeout' => 120,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 128,
                'nice' => 0,
            ],
```

- [ ] **Step 4: Add scheduler entry**

In `bootstrap/app.php`, add inside the `withSchedule` callback, after the existing entries:

```php
        $schedule->command('raiderio:crawl-runs')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 5: Verify command runs with --dry-run**

```bash
docker compose exec app php artisan raiderio:crawl-runs --dry-run
```

Expected: "Raider.io crawl is disabled" (since `RAIDERIO_CRAWL_ENABLED` defaults to false). Set it temporarily to test:

```bash
docker compose exec app php artisan raiderio:crawl-runs --dry-run --env=testing
```

Or add `RAIDERIO_CRAWL_ENABLED=true` to `.env` and re-run. Expected: "Dry run: would dispatch N CrawlCharacterRuns jobs".

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CrawlMythicPlusRuns.php config/raiderio.php config/horizon.php bootstrap/app.php
git commit -m "feat: add raiderio:crawl-runs command, horizon supervisor, and scheduler entry"
```

---

## Task 9: Full Test Suite Verification

- [ ] **Step 1: Run all new tests**

```bash
./vendor/bin/phpunit tests/Unit/Services/RaiderIO/RaiderIOMythicPlusMapperTest.php tests/Unit/Services/RunTeamPersisterTest.php tests/Unit/Services/RaiderIO/RaiderIOClientCrawlTest.php tests/Feature/Services/RaiderIO/CrawlCharacterRunsTest.php tests/Feature/Services/RaiderIO/FetchRunRosterTest.php
```

Expected: All tests PASS.

- [ ] **Step 2: Run existing raiderio + mythic plus tests for regression**

```bash
./vendor/bin/phpunit tests/Unit/Services/RaiderIO/ tests/Feature/Blizzard/Jobs/SyncMythicPlusTeamPivotTest.php
```

Expected: All tests PASS (no regression from RunTeamPersister extraction).

- [ ] **Step 3: Run full test suite**

```bash
composer test
```

Expected: All tests PASS.

- [ ] **Step 4: Run code style check**

```bash
./vendor/bin/pint --test
```

Expected: No style issues. If issues found, run `./vendor/bin/pint` to fix.

---

## Task 10: Manual Smoke Test

- [ ] **Step 1: Run migration if not already done**

```bash
docker compose exec app php artisan migrate
```

- [ ] **Step 2: Enable crawl and run for a small batch**

Add to `.env`:
```
RAIDERIO_CRAWL_ENABLED=true
```

```bash
docker compose exec app php artisan raiderio:crawl-runs --limit=3
```

Expected: "Dispatched 3 CrawlCharacterRuns jobs (season N)."

- [ ] **Step 3: Restart Horizon and monitor**

```bash
docker compose restart horizon
```

Watch Horizon dashboard or logs. Verify:
- `raiderio-crawl` supervisor appears
- `CrawlCharacterRuns` jobs process
- `FetchRunRoster` jobs dispatch and process
- No errors in `storage/logs/laravel.log`

- [ ] **Step 4: Verify data in database**

```bash
docker compose exec app php artisan tinker --execute="
    echo 'Runs with keystone_run_id: ' . \App\Models\DungeonRun::whereNotNull('keystone_run_id')->count() . PHP_EOL;
    echo 'Runs with raiderio_score: ' . \App\Models\DungeonRun::whereNotNull('raiderio_score')->count() . PHP_EOL;
    echo 'Total members: ' . \App\Models\DungeonRunMember::count() . PHP_EOL;
"
```

Expected: Non-zero counts for keystone_run_id and raiderio_score rows, plus member rows.

- [ ] **Step 5: Commit any final adjustments**

If any issues were found during smoke testing, fix and commit.
