# Feature Parity with v1 + Blizzard API Enrichment — Design

- **Status:** approved (brainstorming complete, pending writing-plans)
- **Date:** 2026-04-22
- **Branch target:** v2 (current `master`)
- **Scope:** retail-data enrichment for items/talents/mythic+/pvp/professions/raids; Classic read-through with persistence-ready seam; symmetric force-refresh on both paths.

## 1. Context and motivation

v1 of the API (`implementing-dungeon-runs` branch, Oct 2021) was the reference point for this work. An audit revealed that:

- v1 has no raiding, no Classic support, and uses pre-Dragonflight (10.0) talent mapping that no longer works against the current Blizzard API.
- v1's equipment DTO captures only `id, itemLevel, quality, slot` — no sockets, gems, enchants, stats, bonus list, or set info.
- v2 already surpasses v1 on most dimensions (dynamic mythic+ season detection, modern class/spec/hero talent tree mapping, relational schema, proper rate limiting and health-check middleware, retry policy).

The real work is bringing **v2 up to feature parity with the current Blizzard API**, using v1 only as loose inspiration. This spec covers that enrichment plus Classic read-through.

## 2. Scope

### In scope

- Enriched retail equipment mapping matching the frontend's `WowheadLink.vue` consumption contract (sockets/gems/enchants/bonus/set/stats).
- Character-level mythic+ rating (overall + per-spec).
- PvP brackets (2v2, 3v3, rbg, solo shuffle per-spec) with ratings and season/weekly stats.
- PvP talents (extending `CharacterSpecialization`) plus Blizzard-provided talent loadout code.
- Character professions (primaries + secondaries, with drop/re-add sync semantics).
- Raid encounter summary per character (Blizzard's `/encounters/raids` endpoint).
- Classic read-through at `/characters/classic/{region}/{realm}/{name}`, cached for 15 minutes in Laravel Cache, not persisted.
- Symmetric `?refresh=1` force-refresh on both retail and classic endpoints, throttled to 10 requests/min/IP.
- `game_version` column on `characters` from day one, so future Classic persistence is a feature-flag flip, not a migration.

### Out of scope (deferred)

- Guild raid progression via achievements (dropped after Q3).
- Character titles, achievement summary, statistics (dropped after Q5).
- Raid leaderboards, Raider.io integration, Warcraft Logs.
- Mount/pet/toy/heirloom collections.
- Known recipes per profession.
- Classic talent tree mapping (old tier/column model — will be added when the Classic persistence flag flips).
- OpenAPI file.

## 3. Architecture overview

### Dispatch seam

- `Region` enum keeps its current meaning (geographic: EU/US/KR/TW).
- New `GameVersion` enum decides the Blizzard namespace: `profile-{region}` vs `profile-classic-{region}`.
- Retail continues to flow through `CharacterController::show` → `CharacterService` → queued `SyncCharacterData`.
- Classic flows through `CharacterController::showClassic` → `ClassicCharacterService` → synchronous HTTP call → `Cache::remember` → DTO-backed response.

### Persistence-ready seam for Classic

Today, Classic does not persist. The abstraction preserves the property that **persisting Classic later costs ≈10 lines of code**:

1. `game_version` column and the composite unique index `(name, realm, region, game_version)` exist on day one.
2. Classic uses the **same DTOs and mappers** as retail.
3. `BlizzardClassicProfileClient` is a subclass of `BlizzardProfileClient` overriding only `namespace()`.
4. A feature flag `BLIZZARD_CLASSIC_PERSIST` (default `false`) gates the persistence call inside `ClassicCharacterService`.

### Module layout (additions in bold)

```
app/
├── Enums/
│   ├── GameVersion.php                     ← NEW (Retail | Classic)
│   ├── PvpBracket.php                      ← NEW
│   └── SyncDepth.php                         (unchanged)
├── Blizzard/
│   ├── Client/
│   │   ├── BlizzardProfileClient.php        (extended: 4 new methods)
│   │   └── BlizzardClassicProfileClient.php ← NEW (subclass)
│   ├── DTO/
│   │   ├── EquippedItem.php                 (enriched — sockets/gems/enchants/bonus/stats/set)
│   │   ├── ItemSocket.php                   ← NEW (internal to mapper)
│   │   ├── CharacterSpecialization.php      (adds pvpTalents[] + talentLoadoutCode)
│   │   ├── CharacterMythicPlusRating.php    ← NEW
│   │   ├── PvpBracketStats.php              ← NEW
│   │   ├── CharacterProfession.php          ← NEW
│   │   ├── RaidEncounterKill.php            ← NEW
│   │   └── ClassicCharacterView.php         ← NEW (aggregate wrapper for Classic response)
│   └── Mappers/  (one new mapper per new DTO)
├── Services/
│   ├── CharacterService.php                 (forceRefresh param)
│   └── ClassicCharacterService.php          ← NEW
├── Models/
│   ├── Character.php                         (new columns + relations)
│   ├── CharacterPvpBracket.php              ← NEW
│   ├── CharacterProfession.php              ← NEW
│   └── RaidEncounterKill.php                ← NEW
└── Http/
    ├── Controllers/CharacterController.php  (+ showClassic action)
    └── Resources/
        ├── CharacterResource.php             (extended shape)
        └── ClassicCharacterResource.php     ← NEW
```

### Invariants

1. All existing retail endpoints, job queues, staleness thresholds, and response shapes are preserved (additions are strictly additive).
2. Frontend `WowheadLink.vue` consumes item JSON with no transformation.
3. Classic path never writes to the database (by default).
4. `SyncCharacterData::Standard` cost stays bounded; new heavy fetches live on `SyncDepth::Full`.

## 4. Data model

### `characters` — additive migration

```sql
ALTER TABLE characters
  ADD COLUMN game_version           VARCHAR(20) NOT NULL DEFAULT 'retail',
  ADD COLUMN mythic_plus_rating     SMALLINT NULL,
  ADD COLUMN mythic_plus_rating_by_spec JSONB NULL,  -- { spec_id: rating }
  ADD COLUMN talent_loadout_code    VARCHAR(255) NULL,
  ADD COLUMN pvp_synced_at          TIMESTAMP NULL,
  ADD COLUMN professions_synced_at  TIMESTAMP NULL,
  ADD COLUMN raids_synced_at        TIMESTAMP NULL;

DROP INDEX characters_name_realm_region_unique;
CREATE UNIQUE INDEX characters_identity_unique
  ON characters (name, realm, region, game_version);
```

### Equipment JSONB (per item) — Wowhead-ready

```json
{
  "id": 219325,
  "name": "Djaradin's Pinata",
  "quality": "epic",
  "slot": "head",
  "item_level": 486,
  "bonus":        [7981, 8781, 9144],
  "gems":         [192985, 192958],
  "enchantments": [6652],
  "set_id":       1615,
  "stats": [
    {"type": "haste_rating", "value": 782, "is_negated": false},
    {"type": "versatility",  "value": 556, "is_negated": false}
  ]
}
```

`bonus`, `gems`, `enchantments` are `int[]`, joined by `:` in the frontend's Wowhead URL builder. `gems` preserves socket order. `set_id` lets the frontend build `&pcs=` by collecting all equipped items sharing the same set_id.

### Talents JSONB

```json
{
  "class": [{"id": 123, "rank": 2}],
  "spec":  [{"id": 456, "rank": 1}],
  "hero":  [{"id": 789, "rank": 1}],
  "pvp":   [{"slot": 0, "talent_id": 5555, "spell_id": 41535}]
}
```

`talent_loadout_code` is a separate queryable VARCHAR column on `characters`.

### New table — `character_pvp_brackets`

```
id, character_id FK cascade,
bracket           VARCHAR(32)  -- '2v2' | '3v3' | 'rbg' | 'shuffle-frost-dk' | ...
rating            SMALLINT,
season_won INT, season_lost INT, season_played INT,
weekly_won INT, weekly_lost INT, weekly_played INT,
tier_name         VARCHAR(50) NULL,
timestamps
UNIQUE(character_id, bracket)
```

### New table — `character_professions`

```
id, character_id FK cascade,
profession_id     INT,
profession_name   VARCHAR(100),
tier_name         VARCHAR(100),
skill_points      SMALLINT,
max_skill_points  SMALLINT,
is_primary        BOOLEAN,
timestamps
UNIQUE(character_id, profession_id, tier_name)
```

### New table — `raid_encounter_kills`

```
id, character_id FK cascade,
expansion_name    VARCHAR(100),
instance_id       INT,
instance_name     VARCHAR(150),
encounter_id      INT,
encounter_name    VARCHAR(150),
difficulty        VARCHAR(16),  -- 'lfr' | 'normal' | 'heroic' | 'mythic'
completed_count   INT,
last_kill_timestamp BIGINT NULL,
timestamps
UNIQUE(character_id, encounter_id, difficulty)
INDEX (character_id, difficulty)
INDEX (instance_id, difficulty)
```

### Staleness thresholds (new keys in `config/blizzard.php`)

| Domain | Threshold | Rationale |
|---|---|---|
| `character.profile` | 900s (existing) | - |
| `character.mythic_plus` | 1800s (existing) | - |
| `character.equipment` | 900s (existing) | - |
| `character.pvp` | 1800s (new) | PvP ratings change rapidly on weekends |
| `character.professions` | 21600s / 6 h (new) | Profession changes are rare |
| `character.raids` | 3600s / 1 h (new) | Raid kills happen during raid nights |

### Profession sync write-path

```
BEGIN TRANSACTION
  1. DELETE FROM character_professions
     WHERE character_id = X
       AND is_primary = TRUE
       AND profession_id NOT IN (ids from API response primaries[])
  2. DELETE FROM character_professions
     WHERE character_id = X
       AND is_primary = FALSE
       AND profession_id NOT IN (ids from API response secondaries[])
  3. UPSERT each profession from the response
     on UNIQUE(character_id, profession_id, tier_name)
COMMIT
```

This handles drop-and-replace of primaries, level-up of existing tiers, and addition of new tiers — all without a schema-level "max 2 primaries" constraint (which would be brittle if Blizzard raises the cap).

## 5. Blizzard module

### `BlizzardProfileClient` — new methods

```php
getCharacterPvpSummary(string $realm, string $name): array
  → /profile/wow/character/{realm}/{name}/pvp-summary

getCharacterPvpBracket(string $realm, string $name, string $bracket): array
  → /profile/wow/character/{realm}/{name}/pvp-bracket/{bracket}

getCharacterProfessions(string $realm, string $name): array
  → /profile/wow/character/{realm}/{name}/professions

getCharacterRaidEncounters(string $realm, string $name): array
  → /profile/wow/character/{realm}/{name}/encounters/raids
```

### `BlizzardClassicProfileClient extends BlizzardProfileClient`

Overrides `namespace()` to return `"profile-classic-{region}"`. Registered in `BlizzardServiceProvider`.

### Pool layout inside `SyncCharacterData::Full`

```
Pool 1 (Standard+):   profile, media, equipment, specializations   -- existing
Pool 2 (Full only):   pvp-summary, professions, encounters/raids,
                      mythic-keystone-profile/season/{n}
Pool 3 (Full only):   pvp-bracket/{b} for each b from Pool 2's pvp-summary
```

Within each pool, requests fire in parallel via `Http::pool()`. The three pool *boundaries* are sequential because Pool 3 depends on Pool 2's `pvp-summary` response to know which brackets to request. All inside the existing `SyncCharacterData` job — no new job classes.

### New DTOs (signatures only)

```php
final readonly class EquippedItem {
    public function __construct(
        public int $id, public string $name, public string $quality,
        public string $slot, public int $itemLevel,
        public array $bonus, public array $gems, public array $enchantments,
        public ?int $setId, public array $stats,
    ) {}
}

final readonly class CharacterSpecialization {
    public function __construct(
        public string $activeSpecialization,
        public array $classTalents, public array $specTalents,
        public array $heroTalents, public array $pvpTalents,
        public ?string $talentLoadoutCode,
    ) {}
}

final readonly class CharacterMythicPlusRating {
    public function __construct(
        public int $rating, public string $color, public array $perSpec,
    ) {}
}

final readonly class PvpBracketStats {
    public function __construct(
        public string $bracket, public int $rating,
        public int $seasonWon,  public int $seasonLost,  public int $seasonPlayed,
        public int $weeklyWon,  public int $weeklyLost,  public int $weeklyPlayed,
        public ?string $tierName,
    ) {}
}

final readonly class CharacterProfession {
    public function __construct(
        public int $professionId, public string $professionName,
        public string $tierName, public int $skillPoints,
        public int $maxSkillPoints, public bool $isPrimary,
    ) {}
}

final readonly class RaidEncounterKill {
    public function __construct(
        public string $expansionName,
        public int $instanceId, public string $instanceName,
        public int $encounterId, public string $encounterName,
        public string $difficulty,
        public int $completedCount, public ?int $lastKillTimestamp,
    ) {}
}

final readonly class ClassicCharacterView {
    public function __construct(
        public string $region, public string $realm, public string $name,
        public CharacterProfile $profile, public CharacterMedia $media,
        /** @var EquippedItem[] */ public array $equipment,
        public CharacterSpecialization $spec,
    ) {}
}
```

### Equipment mapper pseudocode

```php
foreach ($data['equipped_items'] as $raw) {
    $gems = [];
    foreach ($raw['sockets'] ?? [] as $socket) {
        if (isset($socket['item']['id'])) {
            $gems[] = (int) $socket['item']['id'];
        }
    }
    $enchants = array_map(
        fn($e) => (int) $e['enchantment_id'],
        $raw['enchantments'] ?? []
    );
    $bonus = array_map('intval', $raw['bonus_list'] ?? []);
    $stats = array_map(fn($s) => [
        'type'       => strtolower($s['type']['type'] ?? ''),
        'value'      => (int) $s['value'],
        'is_negated' => (bool) ($s['is_negated'] ?? false),
    ], $raw['stats'] ?? []);

    $setId = $raw['set']['item_set']['id']
          ?? $raw['set']['id']
          ?? null;

    $items[] = new EquippedItem(
        id:           (int) $raw['item']['id'],
        name:         $raw['name'] ?? '',
        quality:      strtolower($raw['quality']['type'] ?? 'common'),
        slot:         strtolower($raw['slot']['type'] ?? 'unknown'),
        itemLevel:    (int) ($raw['level']['value'] ?? 0),
        bonus:        $bonus,
        gems:         $gems,
        enchantments: $enchants,
        setId:        $setId !== null ? (int) $setId : null,
        stats:        $stats,
    );
}
```

### Specialization mapper additions

Inside the active-loadout branch:

```php
$pvpTalents = array_map(fn($slot) => [
    'slot'      => (int) $slot['slot_number'],
    'talent_id' => (int) ($slot['selected']['talent']['id'] ?? 0),
    'spell_id'  => (int) ($slot['selected']['spell_tooltip']['spell']['id'] ?? 0),
], $loadout['pvp_talent_slots'] ?? []);

$loadoutCode = $loadout['talent_loadout_code'] ?? null;
```

## 6. Sync pipeline

### Job inventory (changes in **bold**)

| Job | Change | Queue |
|---|---|---|
| `SyncCharacterData` | **extended** (Full-depth adds Pool 2 + Pool 3; writes to new tables) | `blizzard-user-sync` |
| `SyncGuildData` | unchanged | `blizzard-user-sync` |
| `SyncGuildRoster` | unchanged | `blizzard-roster-sync` |
| `SyncUserCharacters` | unchanged | `blizzard-user-sync` |
| `ProactiveSyncCharacters` | **extended** (Full for popular/recent, Standard for long tail) | `blizzard-background` |
| `ProactiveSyncGuilds` | unchanged | `blizzard-background` |
| `RefreshClientToken` | unchanged | `blizzard-auth` |

No new job classes.

### `SyncCharacterData::handle()` — Full-depth flow

```
1. Pool 1 fetch → write characters row + JSONB columns

2. if depth == Full:
     Pool 2 fetch (pvp-summary, professions, encounters/raids, mythic-keystone)
     for each slice independently (partial-failure isolated per slice):
       DB::transaction(fn() => syncSlice(...))
     update characters.{pvp,professions,raids,mythics}_synced_at

3. if pvp-summary.brackets is non-empty:
     Pool 3 fetch (pvp-bracket per bracket)
     syncPvpBrackets(...)
```

Per-slice transactions (not one mega-transaction) — one slow slice shouldn't widen locks on unrelated writes.

### Partial-failure semantics

- Pool 2 sub-fetches are independent. If `professions` 404s (characters with no professions), other slices still commit.
- If `encounters/raids` returns 5xx after retry exhaustion, we log and skip *that* slice only.
- `pvp_synced_at` / `professions_synced_at` / `raids_synced_at` / `mythics_synced_at` each update independently on success — a failed slice keeps its timestamp, so the next run retries only what failed.

### Staleness-aware dispatch in `CharacterService::getByIdentity`

```php
public function getByIdentity(string $region, string $realm, string $name, bool $forceRefresh = false): ?Character
{
    $character = Character::byIdentity($name, $realm, $region)->first();
    if (!$character) {
        SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full);
        return null;
    }

    $character->increment('num_of_searches');
    $character->update(['last_searched_at' => now()]);

    if ($forceRefresh) {
        SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full);
        return $character;
    }

    $stale = fn(?Carbon $ts, int $threshold) => !$ts || $ts->diffInSeconds(now()) > $threshold;

    if ($stale($character->mythics_synced_at,     config('blizzard.staleness.character.mythic_plus'))
     || $stale($character->pvp_synced_at,         config('blizzard.staleness.character.pvp'))
     || $stale($character->professions_synced_at, config('blizzard.staleness.character.professions'))
     || $stale($character->raids_synced_at,       config('blizzard.staleness.character.raids'))) {
        SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full);
    } elseif ($character->isStale()) {
        SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard);
    }

    return $character;
}
```

### `ClassicCharacterService`

```php
public function getByIdentity(string $region, string $realm, string $name, bool $forceRefresh = false): ?ClassicCharacterView
{
    if (!config('blizzard.classic.enabled')) return null;

    $key = "classic:view:{$region}:{$realm}:{$name}";
    if ($forceRefresh) Cache::forget($key);

    $view = Cache::remember($key, config('blizzard.classic.cache_seconds'), function () use ($region, $realm, $name) {
        $client = app(BlizzardClassicProfileClient::class, ['region' => $region]);
        $raw    = $client->getCharacterData($realm, $name);
        return new ClassicCharacterView(
            region: $region, realm: $realm, name: $name,
            profile:   $this->profileMapper->map($raw['basic']),
            media:     $this->mediaMapper->map($raw['media']),
            equipment: $this->equipmentMapper->map($raw['equipment']),
            spec:      $this->specMapper->map($raw['specializations']),
        );
    });

    if (config('blizzard.classic.persist')) {
        $this->persistClassicView($view);   // future seam; inert today
    }

    return $view;
}
```

- Classic calls share the same Redis throttle key as retail (`blizzard:rate:{region}`) because Blizzard's rate limit is per-client-credential, not per-namespace.
- Classic calls honor the circuit-breaker flag (`blizzard:health:open`) — fail fast to 503 when set.
- Classic is synchronous (not queue-backed) because there's no persistence to defer to a worker.

## 7. API surface

### Routes

| Method | Path | Middleware |
|---|---|---|
| `GET` | `/api/v1/characters/{region}/{realm}/{character}` | `throttle:10,1` |
| `GET` | `/api/v1/characters/classic/{region}/{realm}/{character}` | `throttle:10,1` |
| `GET` | `/api/v1/guilds/{region}/{realm}/{guild}` | existing |

Other existing routes unchanged.

### Retail response (abbreviated; see `CharacterResource` for full shape)

```json
{
  "data": {
    "id": 42, "name": "dakiboy", "realm": "ravencrest", "region": "eu",
    "game_version": "retail",
    "level": 80, "class_id": 6, "race_id": 4, "faction": "Alliance",
    "average_item_level": 622, "equipped_item_level": 618,
    "active_specialization": "Unholy",
    "talent_loadout_code": "CAMA...",
    "mythic_plus_rating": { "rating": 2840, "color": "#ff8000", "per_spec": { "250": 2840, "251": 1250 } },
    "media": { "avatar": "...", "inset": "...", "main": "..." },
    "talents": {
      "class": [{"id":123,"rank":2}], "spec": [{"id":456,"rank":1}],
      "hero":  [{"id":789,"rank":1}], "pvp":  [{"slot":0,"talent_id":5555,"spell_id":41535}]
    },
    "equipment": [ /* array of Wowhead-ready items */ ],
    "pvp_brackets": [
      {"bracket":"2v2","rating":2100,"tier":"Gladiator",
       "season":{"won":120,"lost":88,"played":208},
       "weekly":{"won":12,"lost":3,"played":15}}
    ],
    "professions": {
      "primaries":   [{"id":164,"name":"Blacksmithing","tier":"Khaz Algar Blacksmithing","skill":100,"max_skill":100,"is_primary":true}],
      "secondaries": [{"id":185,"name":"Cooking","tier":"Khaz Algar Cooking","skill":75,"max_skill":100,"is_primary":false}]
    },
    "raid_progress": {
      "nerub_ar_palace": {
        "expansion": "The War Within",
        "difficulties": { "mythic": {"killed":6,"total":8}, "heroic": {"killed":8,"total":8} },
        "encounters": [ {"id":2902,"name":"Ulgrax the Devourer","difficulty":"mythic","completed_count":4,"last_kill_timestamp":1730000000000} ]
      }
    },
    "guild": {"name": "Karazhan Karkids", "realm": "ravencrest"},
    "dungeon_runs": [ /* existing shape */ ],
    "last_searched_at": "2026-04-22T10:00:00Z",
    "mythics_synced_at": "2026-04-22T09:45:00Z"
  },
  "meta": {
    "game_version": "retail",
    "forced_refresh": false,
    "freshness": {
      "profile": "fresh", "mythic_plus": "fresh", "pvp": "stale",
      "professions": "fresh", "raids": "fresh"
    }
  }
}
```

`meta.freshness.*` values are a fixed enum: `"fresh"` (synced within threshold), `"stale"` (synced but past threshold — a refresh has been dispatched), or `"never_synced"` (slice has never completed for this character).

Partial-data rule: if a slice has never synced (or the last attempt failed), its corresponding top-level `data.*` key is `null`, not omitted. For example, a brand-new character with no PvP history has `data.pvp_brackets: null` and `meta.freshness.pvp: "never_synced"`.

### Classic response (subset, DTO-backed)

```json
{
  "data": {
    "name": "bluetank", "realm": "hydraxian-waterlords", "region": "eu",
    "game_version": "classic",
    "level": 60, "class_id": 1, "race_id": 1, "faction": "Alliance",
    "average_item_level": 66, "equipped_item_level": 66,
    "media": { "avatar": "...", "inset": "...", "main": "..." },
    "equipment": [ /* Wowhead-ready items; may have empty sockets/gems/enchants */ ]
  },
  "meta": {
    "game_version": "classic",
    "cached": false,
    "cache_ttl_seconds": 900,
    "forced_refresh": true
  }
}
```

No mythic+/pvp/professions/raids/talents on Classic — the Classic API doesn't expose those.

### Force-refresh UX contract

| Behavior | Retail `?refresh=1` | Classic `?refresh=1` |
|---|---|---|
| Rate limit | `throttle:10,1` per IP | `throttle:10,1` per IP |
| Cold lookup | 202 + `Retry-After: 5` + Full job dispatched | 200 with fresh fetch (blocking) |
| Warm lookup | 200 + DB row + `X-Data-Staleness: stale` + Full job dispatched | 200 with fresh fetch (cache busted) |
| `meta.forced_refresh` | `true` | `true` |

Asymmetry is inherent: retail is async because it persists; classic is sync because it doesn't.

### Error paths (unchanged contract)

| Condition | Status | Body |
|---|---|---|
| Retail: character not in DB, sync dispatched | 202 | `{"message":"sync dispatched","retry_after":5}` + `Retry-After: 5` |
| Retail: character found | 200 | full resource |
| Classic: Blizzard 404 | 404 | `{"message":"character not found"}` |
| Classic: Blizzard 5xx after retries | 503 | `{"message":"upstream unavailable"}` |
| Rate-limit cascade | 503 | `{"message":"rate limited — please retry"}` + `Retry-After` |
| Throttle tripped | 429 | default Laravel throttle response |
| Classic disabled by flag | 404 | `{"message":"classic support disabled"}` |

## 8. Config changes — `config/blizzard.php`

```php
'staleness' => [
    'character' => [
        'profile'     => 900,
        'mythic_plus' => 1800,
        'equipment'   => 900,
        'pvp'         => 1800,    // NEW
        'professions' => 21600,   // NEW
        'raids'       => 3600,    // NEW
    ],
    'guild' => [ /* unchanged */ ],
],

'classic' => [                    // NEW block
    'enabled'       => env('BLIZZARD_CLASSIC_ENABLED', false),
    'cache_seconds' => env('BLIZZARD_CLASSIC_CACHE_SECONDS', 900),
    'persist'       => env('BLIZZARD_CLASSIC_PERSIST', false),
],
```

## 9. Testing strategy (e2e-only)

One tier of tests: end-to-end against the real Blizzard API, gated by `BLIZZARD_CLIENT_ID` / `BLIZZARD_CLIENT_SECRET`. Skipped when absent, so CI stays green and local runs exercise real contracts.

### Base class — `tests/Feature/Endpoints/EndpointIntegrationTestCase.php`

```php
abstract class EndpointIntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('BLIZZARD_CLIENT_ID') || !env('BLIZZARD_CLIENT_SECRET')) {
            $this->markTestSkipped('Blizzard credentials not configured');
        }
    }

    public const RETAIL_CHARACTERS = [
        'geared_main'     => ['region' => 'eu', 'realm' => '', 'name' => ''],  // fill in: character with sockets, enchants, tier set
        'pvp_player'      => ['region' => 'eu', 'realm' => '', 'name' => ''],  // fill in: active PvP player
        'profession_rich' => ['region' => 'eu', 'realm' => '', 'name' => ''],  // fill in: 2 primaries + secondaries
        'raider'          => ['region' => 'eu', 'realm' => '', 'name' => ''],  // fill in: active raider
    ];

    public const CLASSIC_CHARACTERS = [
        'vanilla_era'  => ['region' => 'eu', 'realm' => '', 'name' => ''],
        'cata_classic' => ['region' => 'eu', 'realm' => '', 'name' => ''],
    ];

    public const GUILDS = [
        ['region' => 'eu', 'realm' => '', 'name' => ''],
    ];
}
```

### `RetailCharacterEndpointTest`

Data-provider-driven across the 4 retail fixtures. Each test asserts:

- Response 200 OK.
- Full `assertJsonStructure` on the Wowhead-ready shape (all nested keys present).
- For every item in `data.equipment`, assert `bonus`, `gems`, `enchantments`, `set_id`, `stats` keys exist with the correct types.
- `data.game_version === 'retail'`.
- `meta.freshness` block present with all five keys.

Force-refresh branch: call the endpoint with `?refresh=1`, then immediately again, assert both responses are valid; the second has `meta.forced_refresh=true` if called again with the param.

### `ClassicCharacterEndpointTest`

Data-provider-driven across the 2 classic fixtures. Each test asserts:

- Response 200 OK.
- `assertJsonStructure` on the classic subset.
- `data.game_version === 'classic'`.
- `meta.forced_refresh === true` when `?refresh=1`.
- No row written to `characters` (`BLIZZARD_CLASSIC_PERSIST=false` default).

### `GuildEndpointTest`

Data-provider over 1–2 guilds. Structural assertions only: roster array present, members have correct fields.

### Partial-data assertion philosophy

Tests assert **shape**, not **specific values**. A test doesn't care whether a PvP rating is 2100 or 2150 — it cares that `rating` is an integer, `season` has the expected sub-keys, and the overall JSON validates.

Running: `./vendor/bin/phpunit tests/Feature/Endpoints/` (added to `composer test:integration`, kept out of default `composer test`).

## 10. Rollout

### Migration order (one deploy per step)

1. **Schema-only migration** — add `game_version` + staleness columns + new tables. Running code is unaware; behavior unchanged. Validates migration safety.
2. **Enriched mappers + extended equipment JSONB + extended specialization DTO** — existing rows update their `equipment` and `talents` JSONB on next stale sync. Backward-compatible at the resource layer.
3. **Pool 2/3 fetches + Full-depth extensions + new sub-table writes** — Horizon starts writing to `character_pvp_brackets`, `character_professions`, `raid_encounter_kills`. Watch queue depth and 429 rate.
4. **Classic read-through + symmetric `?refresh=1`** — ship with `BLIZZARD_CLASSIC_ENABLED=false`. Flip to `true` after smoke test against a real Classic character.

Each step is independently revertible.

### Horizon capacity

Full-depth sync goes from ~5 external calls to ~8–15 per character. Recommend bumping `blizzard-user-sync` worker concurrency by 1 at step 3 and monitoring. No config in this spec — a tunable the team verifies in production.

### Observability / post-deploy checklist

- Horizon dashboard: `blizzard-user-sync` queue depth (expected ~30% rise after step 3).
- Log grep `SyncCharacterData failed` — should stay at pre-change baseline.
- Spot-check high-rating PvP player: verify `pvp_brackets` JSON matches in-game.
- Spot-check Classic character: verify equipment keys populated, no DB write.
- Frontend: load a character with gems in every socket, verify Wowhead tooltip renders full gem/enchant state.

## 11. Open questions / future work

- **Classic talent mapping** — old tier/column trees. Deferred until Classic persistence flag flips. Extension: add nullable `classicTalents` array to `CharacterSpecialization` DTO.
- **Mythic+ season detection for Classic** — not applicable (no keystones in Classic).
- **Per-`?include=` query param** — only if retail payload size becomes a frontend bottleneck. Additive change to `CharacterResource`, no schema impact.
- **Guild raid progression via achievements** — revisit if/when users ask for "guild CE status" features.
- **OpenAPI schema** — not introduced today; Postman collection remains the source of truth.

## 12. Decisions made during brainstorming

- Scope = **option C (full scope with Classic)** — retail enrichment + Classic read-through + persistence-ready abstraction.
- Classic = **read-through only** now, persistence-ready seam.
- Raiding = **character encounter summary only** (no guild progression, no leaderboards).
- Retail extras = **mythic+ rating, PvP brackets, PvP talents, professions, raid encounters** (titles/achievements dropped).
- Approach = **approach 1 (additive, single endpoint)** over sub-resources or include-query.
- Force-refresh = **symmetric on retail and classic** via `?refresh=1` + `throttle:10,1`.
- Tests = **e2e endpoint tests only** with real Blizzard API, partial-shape assertions.
