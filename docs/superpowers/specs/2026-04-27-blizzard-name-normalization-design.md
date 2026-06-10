# Blizzard name normalization & 404 caching

## Problem

End-to-end testing of the character/guild lookup endpoints surfaced four interrelated defects:

1. **Guild names are forwarded to Blizzard verbatim.** `BlizzardProfileClient::getGuildData()` and `getGuildRoster()` build `/data/wow/guild/{realm}/{name}` with the user-supplied name. Blizzard's guild endpoint requires the slug form (lowercase, spaces → hyphens). `Liquid` 404s; `liquid` works. `Attorney at Law` 404s; `attorney-at-law` works. Result: every guild whose name has any uppercase or whitespace is unsyncable. Failed jobs accumulate in `failed_jobs` while the FE polls indefinitely.
2. **`BlizzardProfileClient::getCharacterData()` swallows non-2xx responses.** It builds an `Http::pool()` and returns `$responses['basic']->json()` with no status check and no `->throw()`. A 404 body (`{"code":404, "type":"BLZWEBAPI...", "detail":"Not Found"}`) flows into `CharacterProfileMapper::map()`, which `?? 0`s every missing field. `Character::updateOrCreate()` then writes a garbage row with `level=0`, `faction='Unknown'`, etc. Reproduced live with both `/characters/eu/the-maelstrom/Cirna` (capitalization mismatch, 404 from Blizzard) and `/characters/eu/the-maelstrom/zzzzzznonexistent` (genuinely missing).
3. **Frontend `LookupForm.vue` only slugifies the realm, not the name.** Users typing `"Liquid"` or `"Attorney at Law"` send the unmodified name to the backend, which forwards it to Blizzard, triggering bug 1.
4. **`Character::scopeByIdentity` / `Guild::scopeByIdentity` are case-sensitive.** They use `where('name', $name)`, and PostgreSQL `varchar =` is case-sensitive. Combined with bug 2, this lets `Cirna` and `cirna` coexist as separate rows even though `characters_identity_unique (name, realm, region, game_version)` is supposed to enforce one row per character.

The cumulative impact is broken guild syncs across the board, garbage character rows from typos and casing mistakes, and a confusing FE experience where typos lead to a 60-second poll-until-timeout instead of a quick 404.

## Goals

- Searches with any casing/whitespace in the name resolve to the correct Blizzard entity (or to a clean 404), end to end.
- Blizzard 404s never persist a partially-populated row in `characters` or `guilds`.
- Genuinely-nonexistent names return HTTP 404 to the user within ~5 seconds (one FE poll cycle), instead of stretching to ~60 seconds and looking like a backend timeout.
- The fix uses the existing patterns: thin controllers, services do data work, jobs sync data, Blizzard client wraps the API.

## Non-goals

- Migrating `name` to `citext` or adding `LOWER(name)` functional indexes. Once names are normalized at the controller boundary, the existing case-sensitive `byIdentity` scope is correct.
- Preserving Blizzard's display casing (e.g., `Cirna` instead of `cirna`) in the database. The DB already stores lowercased names today; making it consistent doesn't introduce a regression. Display-side capitalization is a UI concern, out of scope.
- Cleaning up legacy mixed-case rows. The only ones I observed during testing were artifacts of bug 2, and they were already removed during the diagnostic session.
- A persistent `not_found_lookups` table. The not-found state is transient and TTL-bounded by design; cache is a better fit than a table that needs cleanup.
- Stronger 4xx granularity (e.g., 403 `character is hidden`, 410 `gone`). Today's `getGuildData()` lumps all client errors into the failed-job path; that stays unchanged. Only 404 gets the cache-marker treatment.

## Design

### Layer 1 — Single shared identity normalizer

Introduce `App\Support\BlizzardIdentity` (a final class with two static methods, no state):

```php
final class BlizzardIdentity
{
    /** Realms are always ASCII; slugify (lowercase + spaces→hyphens). */
    public static function realm(string $realm): string
    {
        return Str::slug($realm);
    }

    /** Names may contain UTF-8 (e.g., łukasz, élise); lowercase only. */
    public static function name(string $name): string
    {
        return Str::lower(trim($name));
    }
}
```

The split between `realm()` and `name()` is intentional: Blizzard's realm catalog is exclusively ASCII (`the-maelstrom`, `blades-edge`), but character/guild names support UTF-8. `Str::slug` would mangle `Élise` → `elise`, which is wrong (Blizzard's name endpoint expects the URL-encoded UTF-8 form, lowercased). `Str::lower` preserves the codepoints.

### Layer 2 — Apply the normalizer in two places

**Controller entry.** `CharacterController::show` and `GuildController::show` call the normalizer on the route params before doing anything else:

```php
$region = $region; // already constrained by route pattern
$realm  = BlizzardIdentity::realm($realm);
$name   = BlizzardIdentity::name($character); // (or $guild)
```

This is two lines per controller, no `FormRequest` ceremony. `FormRequest`s in this codebase are used for body validation (e.g., auth flows); show endpoints with positional route params are validated by route constraints, and adding a `FormRequest` here just to mutate route params via `$this->route()->setParameter()` is more abstraction than the change earns. Downstream services, jobs, and the client all see normalized values.

**Blizzard client (defense in depth).** `BlizzardProfileClient` is the boundary to the Blizzard API and must defensively normalize even when called from outside the controller path (e.g., `ProactiveSyncCharacters`, `SyncUserCharacters`, future internal callers). Apply `BlizzardIdentity::realm` and `BlizzardIdentity::name` inside `getCharacterData`, `getGuildData`, `getGuildRoster`, `getCharacterMythicPlusPool`, `getCharacterPvpBracketsChunked`, `getCharacterRaidEncounters`, and any other endpoint method that accepts `$realm` / `$name`. Cost: one cheap string op per call; negligible.

The DB unique index `(name, realm, region, game_version)` is satisfied by case-sensitive equality on the normalized values. No schema change.

### Layer 3 — 404 detection in the Blizzard client

Introduce `App\Blizzard\Exceptions\BlizzardNotFoundException` (extends `RuntimeException`). It's a typed signal that means "Blizzard says this entity does not exist," which is distinct from network errors, rate limits, and 5xx — those keep flowing as `RequestException` and exercise the existing retry middleware.

In `BlizzardProfileClient::getCharacterData()`:

```php
$basic = $responses['basic'];
if ($basic->status() === 404) {
    throw new BlizzardNotFoundException("character not found: {$realm}/{$name}");
}
$basic->throw();
```

The other three pooled responses (`media`, `equipment`, `specializations`) are best-effort: a 404 on any of them today already means the existing `if ($response['media']) {…}` guard does the right thing. Leave that intact, but tighten the basic-profile gate.

For `getGuildData()`, replace the bare `$response->throw()` with the same pattern: 404 → `BlizzardNotFoundException`, otherwise `->throw()`. `getGuildRoster()` follows suit. Empty-roster guilds still return 200 with an empty `members` array — that's a valid Blizzard response, not a not-found.

### Layer 4 — Sync jobs catch `BlizzardNotFoundException` and write a cache marker

Inside `SyncCharacterData::handle()` and `SyncGuildData::handle()`, wrap the first Blizzard call in `try/catch (BlizzardNotFoundException)`. On catch:

```php
Cache::put(
    "blizzard:not-found:character:{$this->region}:{$this->realm}:{$this->name}",
    true,
    config('blizzard.not_found_ttl', 86_400)
);
return; // exit the job cleanly — no retry, no failed_jobs row
```

Same pattern for `SyncGuildData`. The job's `failed()` method is unchanged: it still logs other unrecoverable errors. Crucially, throwing `BlizzardNotFoundException` from the catch is **not** done — the job returns normally, so Laravel does not enqueue retries or push to `failed_jobs`.

`config/blizzard.php`:

```php
'not_found_ttl' => env('BLIZZARD_NOT_FOUND_TTL', 86_400), // 24h
```

24 hours is long enough to absorb most retry storms but short enough that a renamed-or-newly-created character becomes searchable within a day.

### Layer 5 — Service consults the cache marker before dispatching

`CharacterService::getByIdentity()` and `GuildService::getByIdentity()` add a not-found check between the existing "not in DB" branch and the dispatch path. Pseudocode for character:

```php
if (! $character) {
    $key = "blizzard:not-found:character:{$region}:{$realm}:{$name}";
    if (Cache::has($key)) {
        throw new EntityNotFoundException();
    }
    return null;
}
```

`App\Exceptions\EntityNotFoundException` is a small typed exception (extends `RuntimeException`). It's caught by the controller, not by Laravel's exception handler, so it stays scoped to this flow.

Controller:

```php
try {
    $result = $service->getByIdentity($region, $realm, $character);
} catch (EntityNotFoundException) {
    return response()->json(['message' => 'Character not found'], 404);
}

if ($result === null) {
    SyncCharacterData::dispatch(...);
    return response()->json([...], 202)->header('Retry-After', '5');
}
```

The FE already handles 404 via `NotFoundError` in `src/api/characters.ts`/`guilds.ts`, so no FE wiring change is needed for this layer.

### Layer 6 — Frontend `LookupForm.vue`

`frontend/src/components/form/LookupForm.vue:18` becomes:

```ts
emit('submit', {
  region: region.value,
  realm: slugify(realm.value),
  name: name.value.trim().toLocaleLowerCase(),
})
```

`toLocaleLowerCase()` (no arg) follows the user's runtime locale, which is correct for UTF-8 names. The BE will re-normalize regardless, so this is purely a UX optimization (avoids a needless 202→eventual-404 round trip) and brings the form's behavior in line with the realm field.

## Data flow with the fix

**Happy path (mixed-case input):**

1. User types `"Cirna"` on the FE → form emits `{name: "cirna"}` → URL `/api/v1/characters/eu/the-maelstrom/cirna`.
2. Controller calls `BlizzardIdentity::name('cirna')` → still `cirna` (idempotent).
3. Service `byIdentity('cirna', 'the-maelstrom', 'eu')` finds the row → 200 with the cached resource.
4. If no row, dispatch `SyncCharacterData('cirna',...)`. Job calls Blizzard with `cirna`, gets 200, persists. FE retries, gets 200.

**Cold-cache typo path (`zzzzzznonexistent`):**

1. URL `/api/v1/characters/eu/the-maelstrom/zzzzzznonexistent`.
2. Service: no row, no cache marker → returns null.
3. Controller dispatches sync, returns 202 + `Retry-After: 5`.
4. Job: Blizzard 404 → `BlizzardNotFoundException` → job catches → `Cache::put('blizzard:not-found:character:eu:the-maelstrom:zzzzzznonexistent', true, 86400)` → job exits cleanly.
5. FE polls 5s later. Service: no row, **cache marker hit** → throws `EntityNotFoundException` → controller returns 404.
6. FE's existing `NotFoundError` path renders the "not found" UI.

Total time-to-404: ~5 seconds (one poll cycle), down from the current ~60 seconds (full FE polling timeout) plus a permanent garbage row.

**Repeat search path (cache still warm):**

1. URL hits.
2. Service immediately sees cache marker → 404 returned without touching the queue. No new Blizzard call. Saves API budget.

**Cache-flushed-but-still-nonexistent path:**

1. After Redis flush, the marker is gone.
2. Next search re-dispatches the sync job.
3. Job re-404s, re-writes the marker.
4. Steady state restored.

## Test strategy

**Unit tests (mocked HTTP via `Http::fake`):**

- `BlizzardIdentityTest` — `realm('The Maelstrom')` → `the-maelstrom`; `name('Cirna')` → `cirna`; `name('Élise')` → `élise`; `realm('  blades  edge  ')` → `blades-edge`; `name(' cirna ')` → `cirna`.
- `BlizzardProfileClientTest::test_getCharacterData_throws_on_basic_404` — `Http::fake(['*' => Http::response([…basic…], 404)])` for the `/profile/wow/character/{realm}/{name}` URL; assert `BlizzardNotFoundException`.
- `BlizzardProfileClientTest::test_getGuildData_throws_on_404` — same pattern with `/data/wow/guild/...`.
- `BlizzardProfileClientTest::test_outgoing_url_uses_normalized_identity` — `Http::fake` capturing requests; call `getGuildData('The Maelstrom', 'Attorney at Law')`; assert exactly one request to a URL ending in `/data/wow/guild/the-maelstrom/attorney-at-law`.

**Job tests:**

- `SyncCharacterDataTest::test_404_writes_cache_marker_and_does_not_persist` — fake Blizzard returning 404, run handle, assert no row in `characters`, assert `Cache::has('blizzard:not-found:...')`.
- `SyncGuildDataTest::test_404_writes_cache_marker_and_does_not_persist` — same.

**Service tests:**

- `CharacterServiceTest::test_getByIdentity_throws_when_cache_marker_present` — seed cache, no row, assert `EntityNotFoundException`.
- `GuildServiceTest::test_getByIdentity_throws_when_cache_marker_present` — same.

**Feature tests** (extending `Tests\Feature\Endpoints` or new `Tests\Feature\Http`):

- `CharacterControllerTest::test_404_after_marker_set` — call endpoint, fake Blizzard 404, assert 202; second call asserts 404 + JSON message.
- `CharacterControllerTest::test_garbage_row_not_persisted_on_404` — same setup, assert `characters` table is empty afterwards.
- `CharacterControllerTest::test_mixed_case_url_finds_existing_row` — seed `characters` with `name = 'cirna'`, GET `/characters/eu/the-maelstrom/CIRNA`, assert 200 with the same row (no duplicate).
- `GuildControllerTest::test_guild_with_spaces_normalized_in_sync_url` — fake HTTP, GET `/guilds/us/blades-edge/Attorney%20at%20Law`, assert the outgoing Blizzard request URL contained `attorney-at-law`.

**Existing integration tests** (`tests/Feature/Endpoints/RetailCharacterEndpointTest`, `GuildEndpointTest`) keep passing. The fixtures use mixed-case names (`Melaniya`, `Sconysoprano`) and a spaced realm (`the maelstrom`) — these are the exact cases the fix unblocks. Today they only pass when Blizzard happens to be lenient or when the test was last regenerated against a cached row; after the fix they pass deterministically.

**Frontend:**

- A short Cypress spec or unit test on `LookupForm` confirming the emitted payload is lowercased. The form is small enough that a `vitest`-on-`@vue/test-utils` would do; if no `test` script is wired (per `frontend/CLAUDE.md`, "no unit test runner is wired up in scripts"), add one as part of this change rather than scope-creeping.

## Configuration

`config/blizzard.php`:

```php
'not_found_ttl' => env('BLIZZARD_NOT_FOUND_TTL', 86_400),
```

`.env.example`:

```
BLIZZARD_NOT_FOUND_TTL=86400
```

No other config changes required.

## Migration / deployment

No DB migration. No Blizzard API contract change. No FE-BE wire format change beyond the FE form normalization (already a no-op for the BE since the BE re-normalizes).

A graceful rollout works: ship the BE first (clients still send unnormalized names, BE normalizes them on receipt), then ship the FE. Order is not critical because each layer is independently safe.

## Risks & mitigations

- **Cache flush re-triggers Blizzard syncs for previously-404'd searches.** Accepted. Volume is bounded by the search rate; the rate-limit middleware (80 req/s) is a backstop.
- **Display name regression.** Lowercased names look slightly less polished. Mitigation: CSS `text-transform: capitalize` in the character header component if visual polish matters.
- **Existing capitalized rows in production data.** Verified during diagnostic work that no such rows exist today. If they appear later (e.g., a backfill process), a one-shot `UPDATE characters SET name = LOWER(name) WHERE name <> LOWER(name)` resolves them. Not part of this change.
- **Polymorphic cache key collisions.** Namespaced under `blizzard:not-found:character:` and `blizzard:not-found:guild:`; collisions impossible.
- **`Str::slug('æ')`-style transliteration.** Blizzard accepts non-ASCII names with URL-encoded UTF-8 (`łukasz`, `élise`). `Str::slug` would mangle these into ASCII (`lukasz`, `elise`) and 404. Mitigation is baked into the design: `BlizzardIdentity::name` uses `Str::lower` (UTF-8-safe), `BlizzardIdentity::realm` uses `Str::slug` (correct for Blizzard's realm catalog, which is ASCII-only).
