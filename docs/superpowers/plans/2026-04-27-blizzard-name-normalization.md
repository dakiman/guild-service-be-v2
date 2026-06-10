# Blizzard name normalization & 404 caching — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make character/guild lookups robust to mixed-case input, stop persisting garbage rows on Blizzard 404s, and surface genuinely-nonexistent names as HTTP 404 within ~5 seconds via a TTL'd cache marker.

**Architecture:** Single shared `BlizzardIdentity` normalizer applied at controllers (canonical input) and inside `BlizzardProfileClient` (defense in depth). A typed `BlizzardNotFoundException` flows out of the client on 404; sync jobs catch it, write a Redis/cache marker, exit cleanly (no garbage row, no `failed_jobs` entry). Services consult the marker before dispatching, throw `EntityNotFoundException`; controllers map that to HTTP 404. FE form lowercases the name to match the realm-slugify behavior.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (class-style tests), Vue 3 + TypeScript on the FE. Tests use SQLite in-memory, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.

**Spec:** `docs/superpowers/specs/2026-04-27-blizzard-name-normalization-design.md`

---

## File structure

**New files:**
- `app/Support/BlizzardIdentity.php` — pure helper, two static methods (`realm`, `name`)
- `app/Blizzard/Exceptions/BlizzardNotFoundException.php` — typed signal from client → job
- `app/Exceptions/EntityNotFoundException.php` — typed signal from service → controller
- `tests/Unit/Support/BlizzardIdentityTest.php`
- `tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`
- `tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php`
- `tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php`
- `tests/Unit/Services/CharacterServiceNotFoundTest.php`
- `tests/Unit/Services/GuildServiceNotFoundTest.php`
- `tests/Feature/Http/CharacterControllerNotFoundTest.php`
- `tests/Feature/Http/GuildControllerNotFoundTest.php`

**Modified files:**
- `app/Blizzard/Client/BlizzardProfileClient.php` — normalize identity in URL building, throw `BlizzardNotFoundException` on 404 in `getCharacterData`/`getGuildData`/`getGuildRoster`
- `app/Blizzard/Jobs/SyncCharacterData.php` — catch `BlizzardNotFoundException`, write cache marker, return early
- `app/Blizzard/Jobs/SyncGuildData.php` — same
- `app/Services/CharacterService.php` — check cache marker in not-found branch, throw `EntityNotFoundException`
- `app/Services/GuildService.php` — same
- `app/Http/Controllers/CharacterController.php` — normalize route params, catch `EntityNotFoundException` → 404
- `app/Http/Controllers/GuildController.php` — same
- `config/blizzard.php` — add `not_found_ttl`
- `.env.example` — add `BLIZZARD_NOT_FOUND_TTL=86400`
- `frontend/src/components/form/LookupForm.vue` — `name.value.trim().toLocaleLowerCase()`

**Each task ends with a commit so you can bisect later.**

---

## Task 1: `BlizzardIdentity` helper

**Files:**
- Create: `app/Support/BlizzardIdentity.php`
- Test: `tests/Unit/Support/BlizzardIdentityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BlizzardIdentity;
use Tests\TestCase;

class BlizzardIdentityTest extends TestCase
{
    public function test_realm_lowercases_and_replaces_spaces_with_hyphens(): void
    {
        $this->assertSame('the-maelstrom', BlizzardIdentity::realm('The Maelstrom'));
        $this->assertSame('blades-edge', BlizzardIdentity::realm('Blades Edge'));
    }

    public function test_realm_collapses_runs_of_whitespace_and_trims(): void
    {
        $this->assertSame('blades-edge', BlizzardIdentity::realm('  blades  edge  '));
    }

    public function test_realm_is_idempotent_on_already_slugified_input(): void
    {
        $this->assertSame('the-maelstrom', BlizzardIdentity::realm('the-maelstrom'));
    }

    public function test_name_lowercases_ascii(): void
    {
        $this->assertSame('cirna', BlizzardIdentity::name('Cirna'));
        $this->assertSame('leonardmccoy', BlizzardIdentity::name('LeonardMcCoy'));
    }

    public function test_name_preserves_utf8(): void
    {
        $this->assertSame('élise', BlizzardIdentity::name('Élise'));
        $this->assertSame('łukasz', BlizzardIdentity::name('Łukasz'));
    }

    public function test_name_trims_whitespace(): void
    {
        $this->assertSame('cirna', BlizzardIdentity::name('  cirna  '));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Support/BlizzardIdentityTest.php`
Expected: FAIL — `Class "App\Support\BlizzardIdentity" not found`

- [ ] **Step 3: Implement `BlizzardIdentity`**

Create `app/Support/BlizzardIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonicalize Blizzard API path segments.
 *
 * Realms are ASCII (Blizzard's realm catalog has no UTF-8 entries) so
 * Str::slug is correct. Character / guild names support UTF-8 and must
 * be lowercased without ASCII transliteration.
 */
final class BlizzardIdentity
{
    public static function realm(string $realm): string
    {
        return Str::slug($realm);
    }

    public static function name(string $name): string
    {
        return Str::lower(trim($name));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Support/BlizzardIdentityTest.php`
Expected: PASS — 6 tests, 12 assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Support/BlizzardIdentity.php tests/Unit/Support/BlizzardIdentityTest.php
git commit -m "Add BlizzardIdentity normalizer for realm/name canonicalization"
```

---

## Task 2: `BlizzardNotFoundException` typed signal

**Files:**
- Create: `app/Blizzard/Exceptions/BlizzardNotFoundException.php`

This is a 5-line typed exception. No standalone test — it's exercised by the `BlizzardProfileClient` tests in Task 3.

- [ ] **Step 1: Create the exception**

```php
<?php

declare(strict_types=1);

namespace App\Blizzard\Exceptions;

use RuntimeException;

/**
 * Thrown by BlizzardProfileClient when Blizzard returns 404 for a
 * character or guild basic profile. Distinguishes "entity does not
 * exist" from network/auth/5xx errors so sync jobs can write a
 * not-found cache marker instead of treating it as a transient failure.
 */
class BlizzardNotFoundException extends RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Blizzard/Exceptions/BlizzardNotFoundException.php
git commit -m "Add BlizzardNotFoundException typed signal for 404 responses"
```

---

## Task 3: `BlizzardProfileClient` — normalize URL + throw on 404

**Files:**
- Modify: `app/Blizzard/Client/BlizzardProfileClient.php`
- Test: `tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`

- [ ] **Step 1: Write the failing test for URL normalization**

Create `tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlizzardProfileClientTest extends TestCase
{
    private function makeClient(string $region = 'us'): BlizzardProfileClient
    {
        $tokenManager = new class implements TokenManagerInterface {
            public function getToken(string $region): string { return 'fake-token'; }
        };

        return new BlizzardProfileClient($tokenManager, $region);
    }

    public function test_get_guild_data_normalizes_realm_and_name_in_url(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(['name' => 'attorney-at-law'], 200),
        ]);

        $this->makeClient('us')->getGuildData('Blades Edge', 'Attorney at Law');

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/data/wow/guild/blades-edge/attorney-at-law');
        });
    }

    public function test_get_guild_data_throws_blizzard_not_found_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        $this->expectException(BlizzardNotFoundException::class);

        $this->makeClient('us')->getGuildData('illidan', 'liquid-disbanded');
    }

    public function test_get_character_data_throws_blizzard_not_found_on_basic_404(): void
    {
        // Pool issues four parallel requests; faking the basic endpoint as 404
        // is enough to trip the gate. The other three URLs share the same prefix
        // so they're 404'd too — that's fine, the basic-only check is what matters.
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        $this->expectException(BlizzardNotFoundException::class);

        $this->makeClient('eu')->getCharacterData('the-maelstrom', 'zzzzzznonexistent');
    }

    public function test_get_character_data_normalizes_realm_and_name_in_url(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response([
                'name' => 'Cirna', 'level' => 90, 'achievement_points' => 100,
                'average_item_level' => 240, 'equipped_item_level' => 240,
                'gender' => ['name' => 'Female'], 'faction' => ['name' => 'Alliance'],
                'race' => ['id' => 4], 'character_class' => ['id' => 5],
            ], 200),
        ]);

        $this->makeClient('eu')->getCharacterData('The Maelstrom', 'Cirna');

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/profile/wow/character/the-maelstrom/cirna?')
                || str_ends_with(parse_url($req->url(), PHP_URL_PATH) ?? '', '/profile/wow/character/the-maelstrom/cirna');
        });
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`
Expected: FAIL — at minimum the 404 tests fail because the current code doesn't throw; URL tests fail because the current code passes the literal name through.

- [ ] **Step 3: Modify `getCharacterData` to normalize and check status**

Open `app/Blizzard/Client/BlizzardProfileClient.php`. Add the imports:

```php
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use App\Support\BlizzardIdentity;
```

Replace the body of `getCharacterData` (currently lines 22-70) so the first lines normalize and the basic response is gated on 404:

```php
public function getCharacterData(string $realm, string $name): array
{
    $realm = BlizzardIdentity::realm($realm);
    $name = BlizzardIdentity::name($name);

    $basePath = "/profile/wow/character/{$realm}/{$name}";
    $token = $this->tokenManager->getToken($this->region);
    $namespace = $this->namespace();
    $baseUrl = $this->baseUrl();
    $timeout = (int) config('blizzard.timeouts.character_pool', 20);

    $responses = Http::pool(fn (Pool $pool) => [
        $pool->as('basic')
            ->withToken($token)
            ->baseUrl($baseUrl)
            ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
            ->timeout($timeout)
            ->connectTimeout(5)
            ->get($basePath),

        $pool->as('media')
            ->withToken($token)
            ->baseUrl($baseUrl)
            ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
            ->timeout($timeout)
            ->connectTimeout(5)
            ->get("{$basePath}/character-media"),

        $pool->as('equipment')
            ->withToken($token)
            ->baseUrl($baseUrl)
            ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
            ->timeout($timeout)
            ->connectTimeout(5)
            ->get("{$basePath}/equipment"),

        $pool->as('specializations')
            ->withToken($token)
            ->baseUrl($baseUrl)
            ->withQueryParameters(['namespace' => $namespace, 'locale' => 'en_GB'])
            ->timeout($timeout)
            ->connectTimeout(5)
            ->get("{$basePath}/specializations"),
    ]);

    $basic = $responses['basic'];
    if ($basic->status() === 404) {
        throw new BlizzardNotFoundException("character not found: {$this->region}/{$realm}/{$name}");
    }
    $basic->throw();

    return [
        'basic' => $basic->json(),
        'media' => $responses['media']->successful() ? $responses['media']->json() : null,
        'equipment' => $responses['equipment']->successful() ? $responses['equipment']->json() : null,
        'specializations' => $responses['specializations']->successful() ? $responses['specializations']->json() : null,
    ];
}
```

> **Why the `successful()` switch on the optional slices:** today the code returns `$responses['media']->json()` which would return the parsed 404 body verbatim. The downstream `if ($response['media']) { ... }` guard does not actually distinguish a successful empty response from a 404 body — both are truthy. Switching to `successful() ? ->json() : null` makes the optional-slice contract explicit and gives the existing guards something meaningful to check.

- [ ] **Step 4: Modify `getGuildData` to normalize and throw typed**

Replace the existing `getGuildData` method (around lines 193-200):

```php
public function getGuildData(string $realm, string $guild): array
{
    $realm = BlizzardIdentity::realm($realm);
    $guild = BlizzardIdentity::name($guild);

    $response = $this->request()
        ->get("/data/wow/guild/{$realm}/{$guild}");

    if ($response->status() === 404) {
        throw new BlizzardNotFoundException("guild not found: {$this->region}/{$realm}/{$guild}");
    }
    $response->throw();

    return $response->json();
}
```

> **Note:** the `request()` builder calls `->retry(...)` which is configured to *not* retry 404s already (see `BlizzardClient::request` line 41-44). So a 404 returns through `$response`; we explicitly raise the typed exception before hitting the generic `->throw()`.

- [ ] **Step 5: Modify `getGuildRoster` to normalize and throw typed**

Replace `getGuildRoster` (around lines 202-210):

```php
public function getGuildRoster(string $realm, string $guild): array
{
    $realm = BlizzardIdentity::realm($realm);
    $guild = BlizzardIdentity::name($guild);

    $response = $this->request()
        ->get("/data/wow/guild/{$realm}/{$guild}/roster");

    if ($response->status() === 404) {
        throw new BlizzardNotFoundException("guild roster not found: {$this->region}/{$realm}/{$guild}");
    }
    $response->throw();

    return $response->json();
}
```

- [ ] **Step 6: Normalize the remaining endpoints**

Apply `BlizzardIdentity::realm/name` at the top of each remaining method that takes `$realm` / `$name`. For each method in `app/Blizzard/Client/BlizzardProfileClient.php` listed below, add the two normalization lines as the first lines after the parameter list:

Methods to update: `getCharacterMythicPlusPool`, `getCharacterPvpSummary`, `getCharacterPvpBracketsChunked`, `getCharacterProfessions`, `getCharacterRaidEncounters`. Pattern:

```php
public function getCharacterRaidEncounters(string $realm, string $name): ?array
{
    $realm = BlizzardIdentity::realm($realm);
    $name = BlizzardIdentity::name($name);

    $response = $this->request()
        ->get("/profile/wow/character/{$realm}/{$name}/encounters/raids");

    // ...rest unchanged
}
```

Use `grep -n "function get.*string \$realm" app/Blizzard/Client/BlizzardProfileClient.php` to find every one. Don't normalize methods that don't take a name (none today, but be careful).

- [ ] **Step 7: Run the new test, verify it passes**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php`
Expected: PASS — 4 tests, all passing.

- [ ] **Step 8: Run the full unit suite to make sure nothing else broke**

Run: `docker compose exec app ./vendor/bin/phpunit --testsuite=Unit`
Expected: PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add app/Blizzard/Client/BlizzardProfileClient.php tests/Unit/Blizzard/Client/BlizzardProfileClientTest.php
git commit -m "Normalize identity and surface typed 404 in BlizzardProfileClient"
```

---

## Task 4: `not_found_ttl` config + env

**Files:**
- Modify: `config/blizzard.php`
- Modify: `.env.example`

- [ ] **Step 1: Add the config key**

In `config/blizzard.php`, add a new section near the `staleness` block:

```php
/*
|--------------------------------------------------------------------------
| Not-Found Cache TTL (seconds)
|--------------------------------------------------------------------------
| When Blizzard returns 404 for a character/guild lookup, we cache that
| result so subsequent searches return HTTP 404 immediately instead of
| re-dispatching a sync job that will 404 again. Default 24h: long enough
| to absorb retry storms, short enough that a renamed/created entity
| becomes searchable within a day.
*/

'not_found_ttl' => (int) env('BLIZZARD_NOT_FOUND_TTL', 86_400),
```

- [ ] **Step 2: Add to `.env.example`**

Append to `.env.example` (preserve existing layout — group near the other `BLIZZARD_*` vars):

```
BLIZZARD_NOT_FOUND_TTL=86400
```

- [ ] **Step 3: Commit**

```bash
git add config/blizzard.php .env.example
git commit -m "Add BLIZZARD_NOT_FOUND_TTL config (default 24h)"
```

---

## Task 5: `SyncCharacterData` — catch 404, write cache marker

**Files:**
- Modify: `app/Blizzard/Jobs/SyncCharacterData.php`
- Test: `tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCharacterDataNotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_writes_cache_marker_and_does_not_persist_a_row(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(
                ['code' => 404, 'type' => 'BLZWEBAPI00000404', 'detail' => 'Not Found'],
                404
            ),
            // any token-fetch the job would need is mocked; we don't dispatch RefreshClientToken here
        ]);

        // dispatchSync runs the job in-process under queue=sync (set in phpunit.xml)
        SyncCharacterData::dispatchSync('eu', 'the-maelstrom', 'zzzzzznonexistent');

        $this->assertTrue(
            Cache::has('blizzard:not-found:character:eu:the-maelstrom:zzzzzznonexistent'),
            'expected not-found cache marker to be set'
        );
        $this->assertSame(0, Character::query()->count(), 'no character row should be persisted on 404');
    }
}
```

> **TokenManager note:** The job calls `$tokenManager->getToken($region)` via the container. Default binding talks to OAuth — that won't fly in tests. Bind a fake in `setUp()` of the test class (see Step 2 below).

- [ ] **Step 2: Add a setUp() to the test class to bind a fake TokenManager**

Add inside the test class (above the test methods):

```php
protected function setUp(): void
{
    parent::setUp();

    $this->app->bind(\App\Blizzard\Contracts\TokenManagerInterface::class, fn () => new class implements \App\Blizzard\Contracts\TokenManagerInterface {
        public function getToken(string $region): string { return 'fake-token'; }
    });
}
```

- [ ] **Step 3: Run the test, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php`
Expected: FAIL — currently the job returns garbage data on 404 and creates a `Character` row, so both assertions fail.

- [ ] **Step 4: Modify `SyncCharacterData::handle()` to catch the typed exception**

Open `app/Blizzard/Jobs/SyncCharacterData.php`. Add imports:

```php
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use Illuminate\Support\Facades\Cache;
```

Wrap the first Blizzard call in `handle()`. Find the line `$response = $client->getCharacterData($this->realm, $this->name);` (appears in both the `Shallow` and `Standard/Full` branches) and refactor so a single try/catch governs both paths:

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
    $client = new BlizzardProfileClient($tokenManager, $this->region);

    try {
        $response = $client->getCharacterData($this->realm, $this->name);
    } catch (BlizzardNotFoundException) {
        Cache::put(
            "blizzard:not-found:character:{$this->region}:{$this->realm}:{$this->name}",
            true,
            (int) config('blizzard.not_found_ttl', 86_400)
        );
        return;
    }

    $profile = $profileMapper->map($response['basic']);

    $characterData = [
        'name' => $this->name,
        'realm' => $this->realm,
        'region' => $this->region,
    ];

    if ($this->depth === SyncDepth::Shallow) {
        $characterData = array_merge($characterData, [
            'gender' => $profile->gender,
            'faction' => $profile->faction,
            'race_id' => $profile->raceId,
            'class_id' => $profile->classId,
            'level' => $profile->level,
            'achievement_points' => $profile->achievementPoints,
            'average_item_level' => $profile->averageItemLevel,
            'equipped_item_level' => $profile->equippedItemLevel,
        ]);
    } else {
        $characterData = array_merge($characterData, [
            'gender' => $profile->gender,
            'faction' => $profile->faction,
            'race_id' => $profile->raceId,
            'class_id' => $profile->classId,
            'level' => $profile->level,
            'achievement_points' => $profile->achievementPoints,
            'average_item_level' => $profile->averageItemLevel,
            'equipped_item_level' => $profile->equippedItemLevel,
        ]);

        if ($response['media']) {
            $media = $mediaMapper->map($response['media']);
            $characterData['media'] = [
                'avatar' => $media->avatar,
                'inset' => $media->inset,
                'main' => $media->main,
            ];
        }

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
    }

    // (rest of handle() — upsert, guild link, slice fan-out — unchanged)
    $character = Character::updateOrCreate(
        [
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
            'game_version' => 'retail',
        ],
        $characterData,
    );

    if ($profile->guildName && $profile->guildRealm) {
        $guild = Guild::firstOrCreate(
            [
                'name' => $profile->guildName,
                'realm' => $profile->guildRealm,
                'region' => $this->region,
            ],
            [
                'faction' => $profile->faction,
            ],
        );

        $character->update(['guild_id' => $guild->id]);
    }

    if ($this->userId !== null) {
        $character->update(['user_id' => $this->userId]);
    }

    if ($this->depth === SyncDepth::Full) {
        $this->syncMythicPlus($client, $gameDataClient, $mythicPlusMapper, $ratingMapper, $character);
        $this->syncPvpData($client, $pvpMapper, $character);
        $this->syncProfessions($client, $professionMapper, $character);
        $this->syncRaidEncounters($client, $raidMapper, $character);
    }
}
```

> **Important:** The duplicated `array_merge` block (Shallow vs Standard/Full) was already in the original code and is unrelated to this fix. Keep it; do not refactor.

- [ ] **Step 5: Run test, verify pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php`
Expected: PASS — 1 test, 2 assertions.

- [ ] **Step 6: Commit**

```bash
git add app/Blizzard/Jobs/SyncCharacterData.php tests/Unit/Blizzard/Jobs/SyncCharacterDataNotFoundTest.php
git commit -m "Cache 404 marker in SyncCharacterData; do not persist garbage rows"
```

---

## Task 6: `SyncGuildData` — catch 404, write cache marker

**Files:**
- Modify: `app/Blizzard/Jobs/SyncGuildData.php`
- Test: `tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncGuildData;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncGuildDataNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(\App\Blizzard\Contracts\TokenManagerInterface::class, fn () => new class implements \App\Blizzard\Contracts\TokenManagerInterface {
            public function getToken(string $region): string { return 'fake-token'; }
        });
    }

    public function test_404_writes_cache_marker_and_does_not_persist_a_row(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(
                ['code' => 404, 'type' => 'BLZWEBAPI00000404', 'detail' => 'Not Found'],
                404
            ),
        ]);

        SyncGuildData::dispatchSync('us', 'illidan', 'zzz-disbanded-guild');

        $this->assertTrue(
            Cache::has('blizzard:not-found:guild:us:illidan:zzz-disbanded-guild'),
            'expected not-found cache marker to be set'
        );
        $this->assertSame(0, Guild::query()->count(), 'no guild row should be persisted on 404');
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php`
Expected: FAIL — currently the job throws on 404 (because `getGuildData` `->throw()`s), the job retries, eventually fails. Cache marker not set.

- [ ] **Step 3: Modify `SyncGuildData::handle()`**

Open `app/Blizzard/Jobs/SyncGuildData.php`. Add imports:

```php
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use Illuminate\Support\Facades\Cache;
```

Wrap the `getGuildData` call (around line 63) in a try/catch:

```php
public function handle(
    TokenManagerInterface $tokenManager,
    GuildProfileMapper $profileMapper,
    GuildRosterMapper $rosterMapper,
): void {
    $client = new BlizzardProfileClient($tokenManager, $this->region);

    try {
        $guildData = $client->getGuildData($this->realm, $this->name);
    } catch (BlizzardNotFoundException) {
        Cache::put(
            "blizzard:not-found:guild:{$this->region}:{$this->realm}:{$this->name}",
            true,
            (int) config('blizzard.not_found_ttl', 86_400)
        );
        return;
    }

    $profile = $profileMapper->map($guildData);

    // ...rest of handle() unchanged: upsert guild, fetch roster, etc.
    $guild = Guild::updateOrCreate(
        [
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
        ],
        [
            'faction' => $profile->faction,
            'achievement_points' => $profile->achievementPoints,
            'member_count' => $profile->memberCount,
            'created_timestamp' => $profile->createdTimestamp,
        ],
    );

    $rosterData = $client->getGuildRoster($this->realm, $this->name);
    $members = $rosterMapper->map($rosterData);

    // ...the rest of the existing roster upsert / member-cleanup / SyncGuildRoster
    // dispatch logic stays exactly as it was.
}
```

> **Note:** the second call (`getGuildRoster`) can also throw `BlizzardNotFoundException` if the guild was disbanded between the two pool calls (rare). Leave it to bubble — that flows through the existing `failed()` handler. We only short-circuit on the *first* call, where 404 means "guild does not exist, do not persist".

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php`
Expected: PASS — 1 test, 2 assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Blizzard/Jobs/SyncGuildData.php tests/Unit/Blizzard/Jobs/SyncGuildDataNotFoundTest.php
git commit -m "Cache 404 marker in SyncGuildData; do not persist garbage rows"
```

---

## Task 7: `EntityNotFoundException` typed signal

**Files:**
- Create: `app/Exceptions/EntityNotFoundException.php`

- [ ] **Step 1: Create the exception**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by Character/Guild services when a not-found cache marker is
 * present, signalling the entity has been confirmed missing on Blizzard
 * within the configured TTL. Controllers translate this to HTTP 404.
 */
class EntityNotFoundException extends RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Exceptions/EntityNotFoundException.php
git commit -m "Add EntityNotFoundException for service-to-controller 404 signal"
```

---

## Task 8: `CharacterService` — consult cache marker

**Files:**
- Modify: `app/Services/CharacterService.php`
- Test: `tests/Unit/Services/CharacterServiceNotFoundTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\EntityNotFoundException;
use App\Services\CharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CharacterServiceNotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_entity_not_found_when_marker_present_and_no_row(): void
    {
        Cache::put('blizzard:not-found:character:eu:the-maelstrom:zzz', true, 60);

        $this->expectException(EntityNotFoundException::class);

        app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'zzz');
    }

    public function test_returns_null_and_does_not_throw_when_marker_absent_and_no_row(): void
    {
        $result = app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'zzz');

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/CharacterServiceNotFoundTest.php`
Expected: FAIL — current service returns null in both cases; the marker test fails because nothing throws.

- [ ] **Step 3: Modify `CharacterService::getByIdentity`**

Open `app/Services/CharacterService.php`. Add imports:

```php
use App\Exceptions\EntityNotFoundException;
use Illuminate\Support\Facades\Cache;
```

Replace the early-return for the not-found case:

```php
public function getByIdentity(string $region, string $realm, string $name, bool $forceRefresh = false): ?Character
{
    $character = Character::byIdentity($name, $realm, $region)->first();

    if (! $character) {
        if (Cache::has("blizzard:not-found:character:{$region}:{$realm}:{$name}")) {
            throw new EntityNotFoundException();
        }
        return null;
    }

    // ...rest of getByIdentity unchanged
    $character->increment('num_of_searches');
    $character->update(['last_searched_at' => now()]);

    $anySliceStale = $character->isMythicsStale()
        || $character->isPvpStale()
        || $character->isProfessionsStale()
        || $character->isRaidsStale();

    if ($forceRefresh || $anySliceStale) {
        SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full);
    } elseif ($character->isStale()) {
        SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard);
    }

    return $character;
}
```

- [ ] **Step 4: Run test, verify pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/CharacterServiceNotFoundTest.php`
Expected: PASS — 2 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CharacterService.php tests/Unit/Services/CharacterServiceNotFoundTest.php
git commit -m "CharacterService: throw EntityNotFoundException when 404 marker is cached"
```

---

## Task 9: `GuildService` — consult cache marker

**Files:**
- Modify: `app/Services/GuildService.php`
- Test: `tests/Unit/Services/GuildServiceNotFoundTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\EntityNotFoundException;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GuildServiceNotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_entity_not_found_when_marker_present_and_no_row(): void
    {
        Cache::put('blizzard:not-found:guild:us:illidan:liquid-disbanded', true, 60);

        $this->expectException(EntityNotFoundException::class);

        app(GuildService::class)->getByIdentity('us', 'illidan', 'liquid-disbanded');
    }

    public function test_returns_null_when_marker_absent_and_no_row(): void
    {
        $this->assertNull(app(GuildService::class)->getByIdentity('us', 'illidan', 'no-such-guild'));
    }
}
```

- [ ] **Step 2: Run, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/GuildServiceNotFoundTest.php`
Expected: FAIL — both cases currently return null.

- [ ] **Step 3: Modify `GuildService::getByIdentity`**

Open `app/Services/GuildService.php`. Add imports:

```php
use App\Exceptions\EntityNotFoundException;
use Illuminate\Support\Facades\Cache;
```

Replace the not-found branch:

```php
public function getByIdentity(string $region, string $realm, string $name): ?Guild
{
    $guild = Guild::byIdentity($name, $realm, $region)->first();

    if (! $guild) {
        if (Cache::has("blizzard:not-found:guild:{$region}:{$realm}:{$name}")) {
            throw new EntityNotFoundException();
        }
        return null;
    }

    // ...rest unchanged
    $guild->increment('num_of_searches');
    $guild->update(['last_searched_at' => now()]);

    if ($guild->isStale() || $guild->isRosterStale()) {
        SyncGuildData::dispatch($region, $realm, $name);
    }

    return $guild;
}
```

- [ ] **Step 4: Run test, verify pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Unit/Services/GuildServiceNotFoundTest.php`
Expected: PASS — 2 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GuildService.php tests/Unit/Services/GuildServiceNotFoundTest.php
git commit -m "GuildService: throw EntityNotFoundException when 404 marker is cached"
```

---

## Task 10: `CharacterController` — normalize input + handle 404

**Files:**
- Modify: `app/Http/Controllers/CharacterController.php`
- Test: `tests/Feature/Http/CharacterControllerNotFoundTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Http/CharacterControllerNotFoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CharacterControllerNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(\App\Blizzard\Contracts\TokenManagerInterface::class, fn () => new class implements \App\Blizzard\Contracts\TokenManagerInterface {
            public function getToken(string $region): string { return 'fake-token'; }
        });
    }

    public function test_returns_404_immediately_when_cache_marker_set(): void
    {
        Cache::put('blizzard:not-found:character:eu:the-maelstrom:zzz', true, 60);

        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Character not found']);
    }

    public function test_first_call_dispatches_sync_then_second_call_returns_404_after_marker(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        // First request: 202 + sync runs synchronously (queue=sync) → marker written
        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz')
            ->assertStatus(202)
            ->assertHeader('Retry-After', '5');

        $this->assertSame(0, Character::query()->count(), 'no garbage row');
        $this->assertTrue(Cache::has('blizzard:not-found:character:eu:the-maelstrom:zzz'));

        // Second request: 404 immediately
        $this->getJson('/api/v1/characters/eu/the-maelstrom/zzz')
            ->assertStatus(404);
    }

    public function test_mixed_case_url_finds_existing_lowercased_row(): void
    {
        Character::create([
            'name' => 'cirna',
            'realm' => 'the-maelstrom',
            'region' => 'eu',
            'game_version' => 'retail',
            'faction' => 'Alliance',
            'race_id' => 4,
            'class_id' => 5,
            'level' => 90,
            'achievement_points' => 12000,
            'average_item_level' => 240,
            'equipped_item_level' => 240,
        ]);

        $this->getJson('/api/v1/characters/eu/the-maelstrom/Cirna')
            ->assertOk()
            ->assertJsonPath('data.name', 'cirna');

        $this->assertSame(1, Character::query()->count(), 'no duplicate created');
    }
}
```

- [ ] **Step 2: Run, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/CharacterControllerNotFoundTest.php`
Expected: FAIL — controller doesn't normalize, doesn't catch the exception.

- [ ] **Step 3: Modify `CharacterController::show`**

Open `app/Http/Controllers/CharacterController.php`. Add imports:

```php
use App\Exceptions\EntityNotFoundException;
use App\Support\BlizzardIdentity;
```

Replace `show`:

```php
public function show(string $region, string $realm, string $character, CharacterService $service, Request $request): JsonResponse
{
    $realm = BlizzardIdentity::realm($realm);
    $character = BlizzardIdentity::name($character);

    try {
        $result = $service->getByIdentity($region, $realm, $character);
    } catch (EntityNotFoundException) {
        return response()->json(['message' => 'Character not found'], 404);
    }

    if ($result === null) {
        SyncCharacterData::dispatch($region, $realm, $character, SyncDepth::Standard);

        return response()->json(['message' => 'Character sync initiated'], 202)
            ->header('Retry-After', '5');
    }

    $result->load(['guild', 'dungeonRuns', 'pvpBrackets', 'professions', 'raidEncounterKills']);

    $response = (new CharacterResource($result))->response($request);

    if ($result->isStale()) {
        $response->header('X-Data-Staleness', 'stale');
    }

    return $response;
}
```

- [ ] **Step 4: Run test, verify pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/CharacterControllerNotFoundTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/CharacterController.php tests/Feature/Http/CharacterControllerNotFoundTest.php
git commit -m "CharacterController: normalize identity, return 404 on not-found marker"
```

---

## Task 11: `GuildController` — normalize input + handle 404

**Files:**
- Modify: `app/Http/Controllers/GuildController.php`
- Test: `tests/Feature/Http/GuildControllerNotFoundTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Http/GuildControllerNotFoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuildControllerNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(\App\Blizzard\Contracts\TokenManagerInterface::class, fn () => new class implements \App\Blizzard\Contracts\TokenManagerInterface {
            public function getToken(string $region): string { return 'fake-token'; }
        });
    }

    public function test_returns_404_immediately_when_cache_marker_set(): void
    {
        Cache::put('blizzard:not-found:guild:us:illidan:disbanded', true, 60);

        $this->getJson('/api/v1/guilds/us/illidan/disbanded')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Guild not found']);
    }

    public function test_normalizes_uppercase_and_spaces_in_request_url_to_blizzard(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        // First call dispatches the sync; queue=sync runs it inline
        $this->getJson('/api/v1/guilds/us/blades-edge/Attorney%20at%20Law')
            ->assertStatus(202);

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/data/wow/guild/blades-edge/attorney-at-law');
        });

        $this->assertSame(0, Guild::query()->count(), 'no garbage row');
        $this->assertTrue(Cache::has('blizzard:not-found:guild:us:blades-edge:attorney-at-law'));
    }
}
```

- [ ] **Step 2: Run, verify it fails**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/GuildControllerNotFoundTest.php`
Expected: FAIL.

- [ ] **Step 3: Modify `GuildController::show`**

Open `app/Http/Controllers/GuildController.php`. Add imports:

```php
use App\Exceptions\EntityNotFoundException;
use App\Support\BlizzardIdentity;
```

Replace `show`:

```php
public function show(string $region, string $realm, string $guild, GuildService $service, Request $request): JsonResponse
{
    $realm = BlizzardIdentity::realm($realm);
    $guild = BlizzardIdentity::name($guild);

    try {
        $result = $service->getByIdentity($region, $realm, $guild);
    } catch (EntityNotFoundException) {
        return response()->json(['message' => 'Guild not found'], 404);
    }

    if ($result === null) {
        SyncGuildData::dispatch($region, $realm, $guild);

        return response()->json(['message' => 'Guild sync initiated'], 202)
            ->header('Retry-After', '5');
    }

    $perPage = (int) $request->query('per_page', '50');
    $members = $result->members()->paginate($perPage);

    $response = response()->json([
        'guild' => new GuildResource($result),
        'members' => $members,
    ]);

    if ($result->isStale()) {
        $response->header('X-Data-Staleness', 'stale');
    }

    return $response;
}
```

- [ ] **Step 4: Run, verify pass**

Run: `docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/GuildControllerNotFoundTest.php`
Expected: PASS — 2 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/GuildController.php tests/Feature/Http/GuildControllerNotFoundTest.php
git commit -m "GuildController: normalize identity, return 404 on not-found marker"
```

---

## Task 12: Frontend `LookupForm.vue` — lowercase the name

**Files:**
- Modify: `frontend/src/components/form/LookupForm.vue`

No test added: there's no `vitest` runner configured (per `frontend/CLAUDE.md`) and adding one is out of scope. The Cypress E2E covers the full flow. Behavior is verified manually below.

- [ ] **Step 1: Update the emit payload**

In `frontend/src/components/form/LookupForm.vue`, line 18:

```ts
function onSubmit() {
  if (!canSubmit.value) return
  emit('submit', {
    region: region.value,
    realm: slugify(realm.value),
    name: name.value.trim().toLocaleLowerCase(),
  })
}
```

- [ ] **Step 2: Manual verification**

Run the FE dev server: `cd frontend && npm run dev`. In the form, type `"Cirna"` for character name. Open the browser network tab and submit. Verify the URL is `/api/v1/characters/eu/the-maelstrom/cirna` (lowercase), not `.../Cirna`.

- [ ] **Step 3: Commit**

```bash
cd /home/dakiman/projects/guild-service-v2/frontend
# This file lives in a sibling directory; commit from the FE side if it's a separate repo,
# or from the BE repo root if both are tracked together. Adjust as needed.
git add src/components/form/LookupForm.vue
git commit -m "LookupForm: lowercase name on submit to match BE normalization"
```

> **If the FE is a separate repo:** commit there. If FE files are tracked under the BE repo (single mono-repo layout), `cd` back to backend and add the FE path.

---

## Task 13: End-to-end smoke test against the live containers

**Files:** none modified — this is a verification-only step.

- [ ] **Step 1: Truncate, reset cache, hit endpoints**

Run from the host:

```bash
docker exec guild-service-be-v2-postgres-1 psql -U guild_service -d guild_service -c "TRUNCATE characters, guilds, guild_members, character_pvp_brackets, character_professions, raid_encounter_kills, dungeon_run_members RESTART IDENTITY CASCADE;"
docker exec guild-service-be-v2-redis-1 redis-cli FLUSHALL

# (a) Mixed-case character: should sync and return 200
curl -s -i "http://localhost:8091/api/v1/characters/eu/the-maelstrom/Cirna" -o /tmp/r1.txt -w "1: %{http_code}\n"
sleep 8
curl -s -i "http://localhost:8091/api/v1/characters/eu/the-maelstrom/Cirna" -o /tmp/r2.txt -w "2: %{http_code}\n"

# (b) Genuinely-nonexistent: should 202 → 404
curl -s -i "http://localhost:8091/api/v1/characters/eu/the-maelstrom/zzzzzznonexistent" -o /tmp/r3.txt -w "3: %{http_code}\n"
sleep 8
curl -s -i "http://localhost:8091/api/v1/characters/eu/the-maelstrom/zzzzzznonexistent" -o /tmp/r4.txt -w "4: %{http_code}\n"

# (c) Mixed-case guild with spaces: should sync (or 404 if guild really doesn't exist)
curl -s -i "http://localhost:8091/api/v1/guilds/us/blades-edge/Attorney%20at%20Law" -o /tmp/r5.txt -w "5: %{http_code}\n"
sleep 8
curl -s -i "http://localhost:8091/api/v1/guilds/us/blades-edge/Attorney%20at%20Law" -o /tmp/r6.txt -w "6: %{http_code}\n"
```

Expected outputs:
- 1: 202 (cold, sync dispatched)
- 2: 200 (data ready)
- 3: 202 (cold, sync attempts)
- 4: 404 (marker set after 404)
- 5: 202 (sync dispatched)
- 6: 200 if `attorney-at-law` exists on `blades-edge`, otherwise 404. (Confirmed `Attorney at Law` exists per the original DB dump.)

- [ ] **Step 2: Verify no garbage rows**

```bash
docker exec guild-service-be-v2-postgres-1 psql -U guild_service -d guild_service -c \
  "SELECT name, level, faction FROM characters ORDER BY name;"
```

Expected: only `cirna` (level=90, real faction). NOT `Cirna` (capital C) and NOT `zzzzzznonexistent`.

- [ ] **Step 3: Verify cache marker exists for the nonexistent name**

```bash
docker exec guild-service-be-v2-redis-1 redis-cli --scan --pattern '*not-found*'
```

Expected: at least `guild_service_database_blizzard:not-found:character:eu:the-maelstrom:zzzzzznonexistent` (the prefix is Laravel's default cache key prefix).

- [ ] **Step 4: Verify failed_jobs is clean**

```bash
docker exec guild-service-be-v2-postgres-1 psql -U guild_service -d guild_service -c \
  "SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW() - INTERVAL '5 minutes';"
```

Expected: `0`. (Pre-fix this would show ≥1 SyncGuildData failure for `Attorney at Law`.)

- [ ] **Step 5: Run the full backend test suite**

```bash
docker compose exec app composer test
```

Expected: PASS for all tests in the new files. Existing tests unchanged.

- [ ] **Step 6: Run code style check**

```bash
docker compose exec app ./vendor/bin/pint --test
```

Expected: PASS. If it complains about the new files, run `docker compose exec app ./vendor/bin/pint` and amend the offending commit (or create a follow-up "Apply Pint" commit).

---

## Self-review (post-write)

**Spec coverage:**
- Layer 1 (BlizzardIdentity helper) → Task 1 ✓
- Layer 2 controller-side application → Tasks 10, 11 ✓
- Layer 2 client-side defensive normalization → Task 3 ✓
- Layer 3 (404 detection in client) → Task 3 ✓
- Layer 4 (sync jobs catch + cache marker) → Tasks 5, 6 ✓
- Layer 5 (service consults marker) → Tasks 7, 8, 9 ✓
- Layer 6 (FE form lowercase) → Task 12 ✓
- Config (not_found_ttl) → Task 4 ✓

**Placeholder scan:** No "TBD"/"TODO"/"add appropriate" lines. Every code step shows complete code. No "similar to Task N" stubs.

**Type consistency:** `BlizzardIdentity::realm`/`name`, `BlizzardNotFoundException`, `EntityNotFoundException`, cache key shape `blizzard:not-found:{character|guild}:{region}:{realm}:{name}`, config key `blizzard.not_found_ttl` are used identically across all tasks.

**Spec gaps found:** none. All test scenarios from the spec's "Test strategy" section have a corresponding step in the plan.
