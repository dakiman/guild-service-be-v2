# Homepage Search Autocomplete — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add typeahead suggestions to the two `LookupForm` instances on the homepage (character and guild). Suggestions come from our local Postgres tables, span all realms, and clicking a suggestion auto-navigates to the detail page.

**Architecture:** Two new public BE endpoints (`/characters/suggest`, `/guilds/suggest`) execute a single `LIKE` query against `characters` / `guilds` ranked by prefix-vs-substring tier, then `num_of_searches DESC NULLS LAST`, then `name ASC`. New FE `NameAutocomplete` component (Vue 3 + TanStack Query, debounced 200ms, min 2 chars) replaces the plain `<input>` inside `LookupForm.vue`; picking a suggestion emits a `pick` event that auto-navigates via the existing `onCharacterSubmit`/`onGuildSubmit` handlers in `HomePage.vue`. Realm combobox stays in place for the manual-submit fallback.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (Pest-style is also accepted; this plan uses class-style to match `EndpointIntegrationTestCase`). Vue 3 + TypeScript + TanStack Query + Tailwind/DaisyUI. Cypress for E2E.

**Spec:** `docs/superpowers/specs/2026-05-03-homepage-autocomplete-design.md`

---

## File structure

**New files (backend):**
- `app/Http/Resources/CharacterSuggestionResource.php`
- `app/Http/Resources/GuildSuggestionResource.php`
- `tests/Unit/Models/CharacterScopeNameSearchTest.php`
- `tests/Unit/Models/GuildScopeNameSearchTest.php`
- `tests/Feature/Endpoints/CharacterSuggestEndpointTest.php`
- `tests/Feature/Endpoints/GuildSuggestEndpointTest.php`

**Modified files (backend):**
- `app/Models/Character.php` — add `scopeNameSearch`
- `app/Models/Guild.php` — add `scopeNameSearch`
- `app/Http/Controllers/CharacterController.php` — add `suggest` action
- `app/Http/Controllers/GuildController.php` — add `suggest` action
- `routes/api.php` — register two new GET routes

**New files (frontend):**
- `frontend/src/components/form/NameAutocomplete.vue`
- `frontend/cypress/e2e/home-autocomplete.cy.ts`

**Modified files (frontend):**
- `frontend/src/types/api.ts` — add `CharacterSuggestion`, `GuildSuggestion` types (or split into a new `frontend/src/types/suggest.ts` if preferred — this plan keeps them in `api.ts` to minimize files)
- `frontend/src/api/characters.ts` — add `suggestCharacters(q)`
- `frontend/src/api/guilds.ts` — add `suggestGuilds(q)`
- `frontend/src/components/form/LookupForm.vue` — replace `<input>` with `<NameAutocomplete>`, re-emit `pick`
- `frontend/src/pages/HomePage.vue` — wire `@pick` to existing `onCharacterSubmit` / `onGuildSubmit`

**No DB migrations.** The existing composite UNIQUE index `(name, realm, region)` on both tables has `name` as its leading column. Sequential scans for substring matching are acceptable at current scale (≤ a few thousand guilds, characters table grows only via search/sync). A `text_pattern_ops` index is a later optimization once table size justifies it.

**Each task ends with a commit so you can bisect later.**

---

## Task 1: `Character::scopeNameSearch`

**Files:**
- Modify: `app/Models/Character.php` (add scope after `scopeRecentlySearched` ~line 175)
- Test: `tests/Unit/Models/CharacterScopeNameSearchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterScopeNameSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_query_shorter_than_two_chars(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'the-maelstrom', 'region' => 'eu']);

        $this->assertCount(0, Character::nameSearch('m')->get());
        $this->assertCount(0, Character::nameSearch('')->get());
    }

    public function test_prefix_matches_rank_above_substring_matches(): void
    {
        Character::factory()->create(['name' => 'amelaniya', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 100]);
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 1]);

        $rows = Character::nameSearch('mel')->get();

        $this->assertSame(['melaniya', 'amelaniya'], $rows->pluck('name')->all());
    }

    public function test_within_tier_ranks_by_num_of_searches_desc_then_name_asc(): void
    {
        Character::factory()->create(['name' => 'melb', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 50]);
        Character::factory()->create(['name' => 'mela', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 50]);
        Character::factory()->create(['name' => 'melc', 'realm' => 'r', 'region' => 'kr', 'num_of_searches' => 99]);

        $rows = Character::nameSearch('mel')->get();

        // melc (99) → mela (50, alpha) → melb (50)
        $this->assertSame(['melc', 'mela', 'melb'], $rows->pluck('name')->all());
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Character::factory()->create([
                'name' => 'mel' . $i,
                'realm' => 'r',
                'region' => 'eu',
                'num_of_searches' => $i,
            ]);
        }

        $this->assertCount(8, Character::nameSearch('mel')->get());
    }

    public function test_lowercases_input_so_mixed_case_query_matches_canonical_storage(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'eu']);

        $this->assertCount(1, Character::nameSearch('Mel')->get());
        $this->assertCount(1, Character::nameSearch('MELANIYA')->get());
    }

    public function test_substring_match_works_when_query_is_in_middle_of_name(): void
    {
        Character::factory()->create(['name' => 'xxmelyy', 'realm' => 'r', 'region' => 'eu']);

        $this->assertCount(1, Character::nameSearch('mel')->get());
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/Models/CharacterScopeNameSearchTest.php`
Expected: FAIL with `BadMethodCallException` ("Call to undefined method Character::nameSearch") or similar.

- [ ] **Step 3: Add the scope**

In `app/Models/Character.php`, after `scopeRecentlySearched` (around line 175), add:

```php
public function scopeNameSearch(\Illuminate\Database\Eloquent\Builder $query, string $q, int $limit = 8): \Illuminate\Database\Eloquent\Builder
{
    $needle = strtolower(trim($q));

    if (strlen($needle) < 2) {
        return $query->whereRaw('1 = 0');
    }

    $prefix = $needle . '%';
    $substring = '%' . $needle . '%';

    return $query
        ->where('game_version', 'retail')
        ->where(function ($q) use ($prefix, $substring) {
            $q->where('name', 'LIKE', $prefix)
                ->orWhere('name', 'LIKE', $substring);
        })
        ->orderByRaw('CASE WHEN name LIKE ? THEN 1 ELSE 2 END', [$prefix])
        ->orderByRaw('num_of_searches DESC NULLS LAST')
        ->orderBy('name')
        ->limit($limit);
}
```

> Note: `scopeByIdentity` already filters `game_version='retail'` (see Character.php:162). Apply the same filter here so Classic characters never appear in suggestions. SQLite-in-memory ignores `NULLS LAST` (it's a Postgres extension) but tolerates the syntax — it parses fine and the test fixtures all set `num_of_searches`, so test ordering is deterministic regardless. Production runs on Postgres where the directive is honored.

- [ ] **Step 4: Run the test and confirm it passes**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/Models/CharacterScopeNameSearchTest.php`
Expected: PASS, all 6 tests green.

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Models/Character.php tests/Unit/Models/CharacterScopeNameSearchTest.php
git commit -m "feat(be): Character::scopeNameSearch for autocomplete"
```

---

## Task 2: `Guild::scopeNameSearch`

**Files:**
- Modify: `app/Models/Guild.php` (add scope after `scopeLargestByMembers` ~line 80)
- Test: `tests/Unit/Models/GuildScopeNameSearchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildScopeNameSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_query_shorter_than_two_chars(): void
    {
        Guild::factory()->create(['name' => 'echo', 'realm' => 'tarren-mill', 'region' => 'eu']);

        $this->assertCount(0, Guild::nameSearch('e')->get());
        $this->assertCount(0, Guild::nameSearch('')->get());
    }

    public function test_prefix_matches_rank_above_substring_matches(): void
    {
        Guild::factory()->create(['name' => 'aecho', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 100]);
        Guild::factory()->create(['name' => 'echo', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 1]);

        $rows = Guild::nameSearch('ech')->get();

        $this->assertSame(['echo', 'aecho'], $rows->pluck('name')->all());
    }

    public function test_within_tier_ranks_by_num_of_searches_desc_then_name_asc(): void
    {
        Guild::factory()->create(['name' => 'echb', 'realm' => 'r', 'region' => 'eu', 'num_of_searches' => 50]);
        Guild::factory()->create(['name' => 'echa', 'realm' => 'r', 'region' => 'us', 'num_of_searches' => 50]);
        Guild::factory()->create(['name' => 'echc', 'realm' => 'r', 'region' => 'kr', 'num_of_searches' => 99]);

        $rows = Guild::nameSearch('ech')->get();

        $this->assertSame(['echc', 'echa', 'echb'], $rows->pluck('name')->all());
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Guild::factory()->create([
                'name' => 'ech' . $i,
                'realm' => 'r',
                'region' => 'eu',
                'num_of_searches' => $i,
            ]);
        }

        $this->assertCount(8, Guild::nameSearch('ech')->get());
    }

    public function test_lowercases_input(): void
    {
        Guild::factory()->create(['name' => 'echo', 'realm' => 'r', 'region' => 'eu']);

        $this->assertCount(1, Guild::nameSearch('Ech')->get());
        $this->assertCount(1, Guild::nameSearch('ECHO')->get());
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/Models/GuildScopeNameSearchTest.php`
Expected: FAIL ("Call to undefined method Guild::nameSearch").

- [ ] **Step 3: Add the scope**

In `app/Models/Guild.php`, after `scopeLargestByMembers` (around line 80), add:

```php
public function scopeNameSearch(\Illuminate\Database\Eloquent\Builder $query, string $q, int $limit = 8): \Illuminate\Database\Eloquent\Builder
{
    $needle = strtolower(trim($q));

    if (strlen($needle) < 2) {
        return $query->whereRaw('1 = 0');
    }

    $prefix = $needle . '%';
    $substring = '%' . $needle . '%';

    return $query
        ->where(function ($q) use ($prefix, $substring) {
            $q->where('name', 'LIKE', $prefix)
                ->orWhere('name', 'LIKE', $substring);
        })
        ->orderByRaw('CASE WHEN name LIKE ? THEN 1 ELSE 2 END', [$prefix])
        ->orderByRaw('num_of_searches DESC NULLS LAST')
        ->orderBy('name')
        ->limit($limit);
}
```

> Note: no `game_version` filter — guilds table has no such column.

- [ ] **Step 4: Run and confirm passes**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/Models/GuildScopeNameSearchTest.php`
Expected: PASS, all 5 tests green.

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Models/Guild.php tests/Unit/Models/GuildScopeNameSearchTest.php
git commit -m "feat(be): Guild::scopeNameSearch for autocomplete"
```

---

## Task 3: `CharacterSuggestionResource` + endpoint + route + feature test

**Files:**
- Create: `app/Http/Resources/CharacterSuggestionResource.php`
- Modify: `app/Http/Controllers/CharacterController.php` (add `suggest` action)
- Modify: `routes/api.php` (register route)
- Test: `tests/Feature/Endpoints/CharacterSuggestEndpointTest.php`

- [ ] **Step 1: Write the failing endpoint test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterSuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_suggestions_with_expected_shape(): void
    {
        Character::factory()->create([
            'name' => 'melaniya',
            'realm' => 'the-maelstrom',
            'display_name' => 'Melaniya',
            'display_realm' => 'The Maelstrom',
            'region' => 'eu',
            'class_id' => 8,
            'level' => 80,
            'faction' => 'Horde',
            'num_of_searches' => 5,
        ]);

        $res = $this->getJson('/api/v1/characters/suggest?q=mel');

        $res->assertOk()->assertJson([
            'suggestions' => [[
                'region' => 'eu',
                'realm' => 'the-maelstrom',
                'display_realm' => 'The Maelstrom',
                'name' => 'melaniya',
                'display_name' => 'Melaniya',
                'class_id' => 8,
                'level' => 80,
                'faction' => 'Horde',
            ]],
        ]);
    }

    public function test_short_query_returns_empty_array_with_200(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'eu']);

        $this->getJson('/api/v1/characters/suggest?q=m')->assertOk()->assertJson(['suggestions' => []]);
        $this->getJson('/api/v1/characters/suggest?q=')->assertOk()->assertJson(['suggestions' => []]);
    }

    public function test_missing_q_returns_422(): void
    {
        $this->getJson('/api/v1/characters/suggest')->assertStatus(422);
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Character::factory()->create(['name' => 'mel' . $i, 'realm' => 'r', 'region' => 'eu']);
        }

        $res = $this->getJson('/api/v1/characters/suggest?q=mel')->assertOk();
        $this->assertCount(8, $res->json('suggestions'));
    }

    public function test_classic_characters_are_not_returned(): void
    {
        Character::factory()->create(['name' => 'melaniya', 'realm' => 'r', 'region' => 'eu', 'game_version' => 'classic']);

        $this->getJson('/api/v1/characters/suggest?q=mel')->assertOk()->assertJson(['suggestions' => []]);
    }

    public function test_throttle_returns_429_after_limit(): void
    {
        // Throttle is 60 per minute per IP. Hit it 60 times then expect 429.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/characters/suggest?q=mel');
        }
        $this->getJson('/api/v1/characters/suggest?q=mel')->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Endpoints/CharacterSuggestEndpointTest.php`
Expected: FAIL with 404 (route does not exist).

- [ ] **Step 3: Create the resource**

Create `app/Http/Resources/CharacterSuggestionResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'region' => $this->region,
            'realm' => $this->realm,
            'display_realm' => $this->display_realm,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'class_id' => $this->class_id,
            'level' => $this->level,
            'faction' => $this->faction,
        ];
    }
}
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/CharacterController.php`, add:

```php
use App\Http\Resources\CharacterSuggestionResource;
// ...

public function suggest(Request $request): JsonResponse
{
    $request->validate(['q' => 'required|string|max:64']);

    $rows = Character::nameSearch((string) $request->query('q'))->get();

    return response()->json([
        'suggestions' => CharacterSuggestionResource::collection($rows),
    ]);
}
```

> The `Character` import already exists at the top of the file (line 12). Only add the resource use statement.

- [ ] **Step 5: Register the route**

In `routes/api.php`, in the Character Routes section (around line 61), add:

```php
Route::get('/characters/suggest', [CharacterController::class, 'suggest'])
    ->middleware('throttle:60,1')
    ->name('characters.suggest');
```

Place it **before** the `/characters/{region}/{realm}/{character}` route to avoid the dynamic-segment route swallowing `suggest`.

- [ ] **Step 6: Run the test**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Endpoints/CharacterSuggestEndpointTest.php`
Expected: PASS, all 6 tests green.

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Http/Resources/CharacterSuggestionResource.php app/Http/Controllers/CharacterController.php routes/api.php tests/Feature/Endpoints/CharacterSuggestEndpointTest.php
git commit -m "feat(be): GET /characters/suggest endpoint"
```

---

## Task 4: `GuildSuggestionResource` + endpoint + route + feature test

**Files:**
- Create: `app/Http/Resources/GuildSuggestionResource.php`
- Modify: `app/Http/Controllers/GuildController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Endpoints/GuildSuggestEndpointTest.php`

- [ ] **Step 1: Write the failing endpoint test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildSuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_suggestions_with_expected_shape(): void
    {
        Guild::factory()->create([
            'name' => 'echo',
            'realm' => 'tarren-mill',
            'display_name' => 'Echo',
            'display_realm' => 'Tarren Mill',
            'region' => 'eu',
            'faction' => 'Horde',
            'num_of_searches' => 9,
        ]);

        $res = $this->getJson('/api/v1/guilds/suggest?q=ech');

        $res->assertOk()->assertJson([
            'suggestions' => [[
                'region' => 'eu',
                'realm' => 'tarren-mill',
                'display_realm' => 'Tarren Mill',
                'name' => 'echo',
                'display_name' => 'Echo',
                'faction' => 'Horde',
            ]],
        ]);
    }

    public function test_short_query_returns_empty_array_with_200(): void
    {
        Guild::factory()->create(['name' => 'echo', 'realm' => 'r', 'region' => 'eu']);

        $this->getJson('/api/v1/guilds/suggest?q=e')->assertOk()->assertJson(['suggestions' => []]);
        $this->getJson('/api/v1/guilds/suggest?q=')->assertOk()->assertJson(['suggestions' => []]);
    }

    public function test_missing_q_returns_422(): void
    {
        $this->getJson('/api/v1/guilds/suggest')->assertStatus(422);
    }

    public function test_caps_at_eight_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Guild::factory()->create(['name' => 'ech' . $i, 'realm' => 'r', 'region' => 'eu']);
        }

        $res = $this->getJson('/api/v1/guilds/suggest?q=ech')->assertOk();
        $this->assertCount(8, $res->json('suggestions'));
    }

    public function test_throttle_returns_429_after_limit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/guilds/suggest?q=ech');
        }
        $this->getJson('/api/v1/guilds/suggest?q=ech')->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Endpoints/GuildSuggestEndpointTest.php`
Expected: FAIL with 404.

- [ ] **Step 3: Create the resource**

Create `app/Http/Resources/GuildSuggestionResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuildSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'region' => $this->region,
            'realm' => $this->realm,
            'display_realm' => $this->display_realm,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'faction' => $this->faction,
        ];
    }
}
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/GuildController.php`, add:

```php
use App\Http\Resources\GuildSuggestionResource;
use App\Models\Guild;
// ...

public function suggest(Request $request): JsonResponse
{
    $request->validate(['q' => 'required|string|max:64']);

    $rows = Guild::nameSearch((string) $request->query('q'))->get();

    return response()->json([
        'suggestions' => GuildSuggestionResource::collection($rows),
    ]);
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, in the Guild Routes section (around line 76), add:

```php
Route::get('/guilds/suggest', [GuildController::class, 'suggest'])
    ->middleware('throttle:60,1')
    ->name('guilds.suggest');
```

Place it **before** `/guilds/{region}/{realm}/{guild}`.

- [ ] **Step 6: Run the test**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/Endpoints/GuildSuggestEndpointTest.php`
Expected: PASS, all 5 tests green.

- [ ] **Step 7: Run full suite to confirm no regressions**

Run: `cd backend && composer test`
Expected: PASS (all existing + new tests).

- [ ] **Step 8: Commit**

```bash
cd backend && git add app/Http/Resources/GuildSuggestionResource.php app/Http/Controllers/GuildController.php routes/api.php tests/Feature/Endpoints/GuildSuggestEndpointTest.php
git commit -m "feat(be): GET /guilds/suggest endpoint"
```

---

## Task 5: FE types + API helpers

**Files:**
- Modify: `frontend/src/types/api.ts`
- Modify: `frontend/src/api/characters.ts`
- Modify: `frontend/src/api/guilds.ts`

- [ ] **Step 1: Add types**

In `frontend/src/types/api.ts`, append after the existing exports:

```typescript
export interface CharacterSuggestion {
  region: Region
  realm: string
  display_realm: string | null
  name: string
  display_name: string | null
  class_id: number
  level: number
  faction: string | null
}

export interface GuildSuggestion {
  region: Region
  realm: string
  display_realm: string | null
  name: string
  display_name: string | null
  faction: string
}
```

> Note: per the migrations (`backend/database/migrations/0001_01_01_000004_create_characters_table.php:21` and `..._000003_create_guilds_table.php:16`), `characters.faction` is nullable but `guilds.faction` is NOT NULL. The TS types reflect that — the FE renders the faction icon for every guild row, but only renders it for character rows when the field is non-null.

- [ ] **Step 2: Add `suggestCharacters` helper**

In `frontend/src/api/characters.ts`, append after `fetchPopularCharacters`:

```typescript
import type { CharacterSuggestion } from '@/types/api'

export async function suggestCharacters(q: string): Promise<CharacterSuggestion[]> {
  const res = await api.get<{ suggestions: CharacterSuggestion[] }>('/characters/suggest', {
    params: { q },
  })
  return res.data.suggestions
}
```

> Move the `import type` to the top of the file alongside the existing imports.

- [ ] **Step 3: Add `suggestGuilds` helper**

Read the existing `frontend/src/api/guilds.ts` to follow its import style, then add an analogous helper:

```typescript
import type { GuildSuggestion } from '@/types/api'

export async function suggestGuilds(q: string): Promise<GuildSuggestion[]> {
  const res = await api.get<{ suggestions: GuildSuggestion[] }>('/guilds/suggest', {
    params: { q },
  })
  return res.data.suggestions
}
```

- [ ] **Step 4: Type-check**

Run: `cd frontend && npx vue-tsc -b`
Expected: PASS (no type errors).

- [ ] **Step 5: Commit**

```bash
cd frontend && git add src/types/api.ts src/api/characters.ts src/api/guilds.ts
git commit -m "feat(fe): suggest API helpers and types"
```

---

## Task 6: `NameAutocomplete.vue` component

**Files:**
- Create: `frontend/src/components/form/NameAutocomplete.vue`

This is the largest single task. The component pattern-mirrors `RealmCombobox.vue` for the input/dropdown shell, ARIA roles, and keyboard nav, but its data source is async (TanStack Query) instead of an in-memory list.

- [ ] **Step 1: Re-read `RealmCombobox.vue` end-to-end**

Run: `cat frontend/src/components/form/RealmCombobox.vue`
This is the visual + keyboard-nav reference. Match the dropdown styling, ARIA combobox roles, blur-deferral pattern, and arrow/Enter/Escape keyboard handling.

- [ ] **Step 2: Create the component**

Create `frontend/src/components/form/NameAutocomplete.vue`:

```vue
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useQuery, keepPreviousData } from '@tanstack/vue-query'
import { suggestCharacters } from '@/api/characters'
import { suggestGuilds } from '@/api/guilds'
import ClassIcon from '@/components/wow/ClassIcon.vue'
import FactionBadge from '@/components/wow/FactionBadge.vue'
import { displayName as fmtName, displayRealm as fmtRealm } from '@/utils/display'
import type { CharacterSuggestion, GuildSuggestion, Region } from '@/types/api'
import type { Faction } from '@/types/wow'

type Suggestion =
  | (CharacterSuggestion & { _kind: 'character' })
  | (GuildSuggestion & { _kind: 'guild' })

const props = defineProps<{
  kind: 'character' | 'guild'
  modelValue: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  pick: [payload: { region: Region; realm: string; name: string }]
}>()

const open = ref(false)
const highlightIndex = ref(0)
const inputEl = ref<HTMLInputElement | null>(null)
const debounced = ref('')

let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(
  () => props.modelValue,
  (value) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      debounced.value = value.trim().toLowerCase()
    }, 200)
  },
)

const enabled = computed(() => debounced.value.length >= 2)

const query = useQuery({
  queryKey: computed(() => ['suggest', props.kind, debounced.value] as const),
  queryFn: async (): Promise<Suggestion[]> => {
    const q = debounced.value
    if (props.kind === 'character') {
      const rows = await suggestCharacters(q)
      return rows.map((r) => ({ ...r, _kind: 'character' as const }))
    }
    const rows = await suggestGuilds(q)
    return rows.map((r) => ({ ...r, _kind: 'guild' as const }))
  },
  enabled,
  placeholderData: keepPreviousData,
  staleTime: 30_000,
  retry: false,
})

const suggestions = computed<Suggestion[]>(() => query.data.value ?? [])

watch(suggestions, () => {
  highlightIndex.value = 0
})

function onInput(e: Event) {
  emit('update:modelValue', (e.target as HTMLInputElement).value)
  open.value = true
}

function onFocus() {
  if (props.modelValue.trim()) open.value = true
}

function onBlur() {
  // Defer so click-on-suggestion (mousedown → blur → click) registers.
  setTimeout(() => {
    open.value = false
  }, 120)
}

function onKeydown(e: KeyboardEvent) {
  if (!open.value && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
    open.value = true
    return
  }
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (suggestions.value.length > 0) {
      highlightIndex.value = (highlightIndex.value + 1) % suggestions.value.length
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (suggestions.value.length > 0) {
      highlightIndex.value =
        (highlightIndex.value - 1 + suggestions.value.length) % suggestions.value.length
    }
  } else if (e.key === 'Enter') {
    if (open.value && suggestions.value[highlightIndex.value]) {
      e.preventDefault()
      pick(suggestions.value[highlightIndex.value])
    }
  } else if (e.key === 'Escape') {
    open.value = false
  }
}

function pick(s: Suggestion) {
  emit('pick', { region: s.region, realm: s.realm, name: s.name })
  open.value = false
  inputEl.value?.blur()
}

const placeholder = computed(() =>
  props.kind === 'guild' ? 'Guild name' : 'Character name',
)

const showLoading = computed(() => enabled.value && query.isFetching.value && suggestions.value.length === 0)
const showEmpty = computed(
  () => enabled.value && !query.isFetching.value && suggestions.value.length === 0,
)
</script>

<template>
  <div class="relative">
    <input
      ref="inputEl"
      type="text"
      class="input input-bordered input-sm w-full"
      :value="modelValue"
      :placeholder="placeholder"
      :aria-label="placeholder"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open"
      aria-autocomplete="list"
      @input="onInput"
      @focus="onFocus"
      @blur="onBlur"
      @keydown="onKeydown"
    />

    <div
      v-if="open"
      class="absolute left-0 right-0 mt-1 z-20 rounded-md bg-base-100 border border-base-300 shadow-lg max-h-72 overflow-auto"
      role="listbox"
    >
      <div v-if="showLoading" class="p-3 space-y-2">
        <div class="skeleton h-5 w-full"></div>
        <div class="skeleton h-5 w-full"></div>
        <div class="skeleton h-5 w-full"></div>
      </div>

      <ul v-else-if="suggestions.length" class="py-1">
        <li
          v-for="(s, i) in suggestions"
          :key="`${s.region}:${s.realm}:${s.name}`"
          role="option"
          :aria-selected="i === highlightIndex"
          class="flex items-center gap-2 px-3 py-1.5 cursor-pointer text-sm"
          :class="i === highlightIndex ? 'bg-primary text-primary-content' : 'hover:bg-base-200'"
          @mousedown.prevent="pick(s)"
          @mouseenter="highlightIndex = i"
        >
          <FactionBadge
            v-if="s.faction"
            :faction="(s.faction as Faction)"
            :size="16"
            class="shrink-0"
          />
          <ClassIcon v-if="s._kind === 'character'" :class-id="s.class_id" />
          <span class="font-bold truncate">{{ fmtName(s.name, s.display_name) }}</span>
          <span class="opacity-70 truncate">
            · {{ fmtRealm(s.realm, s.display_realm) }} ({{ s.region.toUpperCase() }})<template
              v-if="s._kind === 'character'"
            >
              · L{{ s.level }}</template>
          </span>
        </li>
      </ul>

      <div v-else-if="showEmpty" class="p-3 text-sm text-base-content/60">
        No matches — pick a realm and submit to search Blizzard.
      </div>
    </div>
  </div>
</template>
```

> Notes:
> - `Faction` type is imported from `@/types/wow` (existing — used by `FactionBadge.vue:13`). The cast `s.faction as Faction` narrows the BE-typed string ('Alliance' | 'Horde' | other) to the FactionBadge prop type. If the BE ever returns a value outside the union, the icon renders as one of the two with a wrong color — acceptable for v1; tighten via runtime guard later if needed.
> - `ClassIcon` is imported from `@/components/wow/ClassIcon.vue` and is used the same way as on `HomePage.vue:79`.
> - On error, the component drops the dropdown silently (`retry: false`, no error branch in template) — never blocks typing.
> - `keepPreviousData` keeps the dropdown stable while debounced query refetches.

- [ ] **Step 3: Type-check**

Run: `cd frontend && npx vue-tsc -b`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add src/components/form/NameAutocomplete.vue
git commit -m "feat(fe): NameAutocomplete component for homepage search"
```

---

## Task 7: Wire `NameAutocomplete` into `LookupForm` and `HomePage`

**Files:**
- Modify: `frontend/src/components/form/LookupForm.vue`
- Modify: `frontend/src/pages/HomePage.vue`

- [ ] **Step 1: Replace input in `LookupForm.vue`**

Replace the entire contents of `frontend/src/components/form/LookupForm.vue` with:

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import RealmCombobox, { type RealmPick } from '@/components/form/RealmCombobox.vue'
import NameAutocomplete from '@/components/form/NameAutocomplete.vue'
import type { Region } from '@/types/api'

defineProps<{ kind: 'character' | 'guild' }>()
const emit = defineEmits<{
  submit: [payload: { region: Region; realm: string; name: string }]
  pick: [payload: { region: Region; realm: string; name: string }]
}>()

const selectedRealm = ref<RealmPick | null>(null)
const name = ref('')

const canSubmit = computed(() => !!selectedRealm.value && !!name.value.trim())

function onSubmit() {
  if (!selectedRealm.value || !name.value.trim()) return
  emit('submit', {
    region: selectedRealm.value.region,
    realm: selectedRealm.value.slug,
    name: name.value.trim().toLocaleLowerCase(),
  })
}

function onPick(payload: { region: Region; realm: string; name: string }) {
  emit('pick', payload)
}
</script>

<template>
  <form class="flex flex-col gap-2" @submit.prevent="onSubmit">
    <div class="flex gap-2">
      <NameAutocomplete
        v-model="name"
        :kind="kind"
        class="w-40 shrink-0"
        @pick="onPick"
      />
      <RealmCombobox v-model="selectedRealm" class="flex-1 min-w-0" />
    </div>
    <button type="submit" class="btn btn-primary btn-sm" :disabled="!canSubmit">
      {{ kind === 'guild' ? 'Find guild' : 'Find character' }}
    </button>
  </form>
</template>
```

- [ ] **Step 2: Wire `@pick` in `HomePage.vue`**

In `frontend/src/pages/HomePage.vue` lines 14 and 20, change the `LookupForm` usages to also forward `@pick`:

```vue
<LookupForm kind="character" @submit="onCharacterSubmit" @pick="onCharacterSubmit" />
```

```vue
<LookupForm kind="guild" @submit="onGuildSubmit" @pick="onGuildSubmit" />
```

> The existing `onCharacterSubmit` / `onGuildSubmit` handlers (HomePage.vue:146 and 153) accept `{region, realm, name}` and call `router.push` — same shape as the `pick` payload, no other changes required.

- [ ] **Step 3: Type-check**

Run: `cd frontend && npx vue-tsc -b`
Expected: PASS.

- [ ] **Step 4: Manual smoke**

Run: `cd frontend && npm run dev`. Open `http://localhost:5173/` (or `http://100.82.124.39:8092/` for the deployed nginx — note `localhost:5173` will not proxy `/api/v1/` per `frontend/CLAUDE.md`). Type 2+ characters into either name input. Suggestions should render. Click one → URL changes to the detail page.

> If the dev-server-direct path (5173) shows no suggestions, that is the expected `.env` `VITE_API_BASE_URL` issue — switch to port 8092 to verify.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add src/components/form/LookupForm.vue src/pages/HomePage.vue
git commit -m "feat(fe): wire NameAutocomplete into homepage forms"
```

---

## Task 8: Cypress smoke test

**Files:**
- Create: `frontend/cypress/e2e/home-autocomplete.cy.ts`

- [ ] **Step 1: Inspect existing Cypress specs to match patterns**

Run: `ls frontend/cypress/e2e/ && head -30 frontend/cypress/e2e/$(ls frontend/cypress/e2e/ | head -1)`
Note: how the existing specs handle BE preconditions (intercept vs real BE), `cy.visit('/')` baseUrl, etc.

- [ ] **Step 2: Write the spec**

Create `frontend/cypress/e2e/home-autocomplete.cy.ts`:

```typescript
describe('Homepage autocomplete', () => {
  it('character name suggestions appear and navigate on pick', () => {
    cy.intercept('GET', '**/api/v1/characters/suggest?*', {
      statusCode: 200,
      body: {
        suggestions: [
          {
            region: 'eu',
            realm: 'the-maelstrom',
            display_realm: 'The Maelstrom',
            name: 'melaniya',
            display_name: 'Melaniya',
            class_id: 8,
            level: 80,
            faction: 'Horde',
          },
        ],
      },
    }).as('suggestChar')

    cy.visit('/')

    cy.get('input[placeholder="Character name"]').type('mela')
    cy.wait('@suggestChar')
    cy.contains('Melaniya').should('be.visible')
    cy.contains('Melaniya').click()
    cy.url().should('include', '/character/eu/the-maelstrom/melaniya')
  })

  it('guild name suggestions appear and navigate on pick', () => {
    cy.intercept('GET', '**/api/v1/guilds/suggest?*', {
      statusCode: 200,
      body: {
        suggestions: [
          {
            region: 'eu',
            realm: 'tarren-mill',
            display_realm: 'Tarren Mill',
            name: 'echo',
            display_name: 'Echo',
            faction: 'Horde',
          },
        ],
      },
    }).as('suggestGuild')

    cy.visit('/')

    cy.get('input[placeholder="Guild name"]').type('ech')
    cy.wait('@suggestGuild')
    cy.contains('Echo').should('be.visible')
    cy.contains('Echo').click()
    cy.url().should('include', '/guild/eu/tarren-mill/echo')
  })

  it('shows empty state when there are no matches', () => {
    cy.intercept('GET', '**/api/v1/characters/suggest?*', {
      statusCode: 200,
      body: { suggestions: [] },
    }).as('suggestEmpty')

    cy.visit('/')

    cy.get('input[placeholder="Character name"]').type('zzzzzz')
    cy.wait('@suggestEmpty')
    cy.contains('No matches').should('be.visible')
  })
})
```

> Confirm that `frontend/src/router/index.ts` declares the character detail route as `/character/:region/:realm/:name` and guild detail as `/guild/:region/:realm/:name`. If either uses a plural or different prefix, adjust the URL assertions accordingly.

- [ ] **Step 3: Run Cypress**

Run (with the dev server running in another shell — `cd frontend && npm run dev`):
```
cd frontend && npx cypress run --spec cypress/e2e/home-autocomplete.cy.ts
```
Expected: 3 tests pass.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add cypress/e2e/home-autocomplete.cy.ts
git commit -m "test(fe): cypress smoke for homepage autocomplete"
```

---

## Self-review

**Spec coverage:**
- BE endpoints `/characters/suggest`, `/guilds/suggest` → Tasks 3 & 4
- Public, throttled 60/min/IP → Tasks 3 & 4 (route middleware + throttle test)
- Min 2 chars returns empty → Tasks 1, 2 (scope), 3, 4 (endpoint)
- `q` missing → 422 → Tasks 3 & 4
- Tier (prefix > substring), `num_of_searches DESC NULLS LAST`, alphabetical fallback, cap at 8 → Tasks 1 & 2
- Resource shapes match spec → Tasks 3 & 4 (resource files)
- `NameAutocomplete` component (Vue 3, TanStack Query, 200ms debounce, min 2, `keepPreviousData`, `staleTime: 30s`) → Task 6
- Faction icon (when non-null), class icon (chars), display formatting helpers → Task 6
- ARIA combobox roles + arrow/Enter/Escape keyboard nav → Task 6
- Auto-navigate on pick via `@pick` event reusing `onCharacterSubmit`/`onGuildSubmit` → Task 7
- Realm combobox stays in place for manual-submit fallback → Task 7
- Cypress smoke (char, guild, empty state) → Task 8

**Placeholder scan:** No "TBD"/"TODO" left. The two open questions in the spec are resolved inline:
- Faction icon component: `FactionBadge.vue` already exists at `frontend/src/components/wow/FactionBadge.vue` — reused in Task 6, no new component needed.
- Name-column index: existing composite UNIQUE `(name, realm, region)` is documented under "File structure" — no migration in this plan.

**Type consistency check:** `nameSearch` scope name is identical in both models and called the same way in both controllers. Resource property shapes match TS types (Task 5) — `display_realm` and `display_name` are nullable on both BE/FE; `faction` is nullable in `CharacterSuggestion`, non-nullable in `GuildSuggestion` (matches migrations). `pick` payload shape `{region, realm, name}` is identical in `NameAutocomplete` (Task 6), `LookupForm` (Task 7), and the existing `HomePage` handlers (HomePage.vue:146-156).
