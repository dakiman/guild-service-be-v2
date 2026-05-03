# Homepage Search Autocomplete

**Date:** 2026-05-03
**Status:** design accepted, pending implementation plan

## Goal

Add typeahead suggestions to the two `LookupForm` instances on the homepage (character and guild). Suggestions are sourced from our own database, span all realms, and clicking a suggestion auto-navigates to the detail page.

## Scope

- Two new BE endpoints (one per kind) that prefix/substring match against `characters` and `guilds`.
- A new FE `NameAutocomplete` component that replaces the plain `<input>` inside `LookupForm.vue`.
- Auto-navigation on suggestion pick. The realm combobox stays in place for the manual-submit fallback (typing a name we have no row for, then picking a realm and submitting — same as today).

Out of scope: external sources (RaiderIO, Blizzard), recency-based ranking, fuzzy/trigram matching. All deferred until usage data justifies them.

## Backend

### Endpoints

- `GET /api/v1/characters/suggest?q=<query>` → `{ suggestions: CharacterSuggestion[] }`
- `GET /api/v1/guilds/suggest?q=<query>` → `{ suggestions: GuildSuggestion[] }`

Public (no auth), matching the existing `popular` and `show` endpoints. Throttle 60/min/IP — keystroke-driven, but not so loose that abuse is cheap.

### Request

- `q`: required string. Trimmed and lowercased server-side before querying (names are stored as canonical-lowercase slugs, see `frontend/CLAUDE.md` "Identity casing").
- If trimmed length < 2: respond `200` with `{ suggestions: [] }`. Not 400 — the FE calls without guarding, and an empty-array contract is simpler.
- If `q` missing entirely: `422` (validation error).

### Query

Single SQL against the table:

```sql
SELECT ...
FROM characters -- or guilds
WHERE name LIKE :prefix OR name LIKE :substring
ORDER BY
  CASE WHEN name LIKE :prefix THEN 1 ELSE 2 END,  -- tier
  num_of_searches DESC NULLS LAST,
  name ASC
LIMIT 8
```

Where `:prefix = "{q}%"` and `:substring = "%{q}%"`.

Names are already canonical-lowercase, so a plain BTREE on `name` accelerates the prefix tier. Substring tier is sequential — acceptable at current scale (a few thousand guilds, characters table grows only as users search). `pg_trgm` GIN index is a later optimization, not part of v1.

No new migrations expected. If `idx_characters_name` / `idx_guilds_name` BTREE indexes don't already exist, add them.

### Resources

New `app/Http/Resources/CharacterSuggestionResource.php`:

```
{
  region: string,
  realm: string,
  display_realm: string | null,
  name: string,
  display_name: string | null,
  class_id: int,
  level: int,
  faction: string | null,
}
```

New `app/Http/Resources/GuildSuggestionResource.php`:

```
{
  region: string,
  realm: string,
  display_realm: string | null,
  name: string,
  display_name: string | null,
  faction: string | null,
}
```

`faction` is nullable on both — guilds seeded by the RaiderIO seeder may lack it; the FE omits the icon when null.

### Controllers

Add `suggest` action to `CharacterController` and `GuildController`. Each delegates to a small dedicated query class (`CharacterSuggestionQuery`, `GuildSuggestionQuery`) that takes a normalized query string and returns up to 8 Eloquent models. Controllers wrap in the resource collection and return.

## Frontend

### New component: `NameAutocomplete.vue`

`frontend/src/components/form/NameAutocomplete.vue`. Pattern-mirrors `RealmCombobox.vue` for the input/dropdown shell, ARIA combobox roles, and keyboard nav (arrows, Enter, Escape, click-outside-to-close).

**Props:** `kind: 'character' | 'guild'`, `modelValue: string` (the typed text — kept as string so manual submit still works when no suggestion is picked).

**Emits:**
- `update:modelValue` (text changes)
- `pick` payload `{ region, realm, name }` — full identity, ready for direct navigation

**Query:**
- TanStack Query, key `['suggest', kind, normalizedQuery]`
- Fires when trimmed `modelValue.length >= 2`
- 200ms debounce on the input → `normalizedQuery` ref
- `keepPreviousData: true` so the dropdown doesn't flash empty between keystrokes
- `staleTime: 30s`

**Dropdown states:**
- Loading: 3 thin skeleton rows
- Empty (`length === 0` and not loading): single line "No matches — pick a realm and submit to search Blizzard"
- Error: silent — drop the dropdown, never block typing
- Populated: list of suggestion rows

**Row layout:**
- Character: `[faction icon] [class icon] DisplayName · Display Realm (REGION) · L<level>`
- Guild: `[faction icon] DisplayName · Display Realm (REGION)`
- Faction icon rendered only when `faction` is non-null. Reuse existing faction icon component from `components/wow/` if present; otherwise add a small `FactionIcon.vue` (Alliance/Horde, ~16px). Plan phase confirms which.
- Class icon: existing `components/wow/ClassIcon.vue` (already used on HomePage's popular lists).
- Display formatting: existing `displayName` / `displayRealm` from `@/utils/display`.

### API helpers

- `frontend/src/api/characters.ts → suggestCharacters(q: string): Promise<CharacterSuggestion[]>`
- `frontend/src/api/guilds.ts → suggestGuilds(q: string): Promise<GuildSuggestion[]>`

Both via the shared `api` axios client. Plain `200`-only — no `SyncPendingError` / 404 special-casing (these endpoints don't sync).

Types added to `frontend/src/types/api.ts` (or a new `suggest.ts` if cleaner).

### `LookupForm.vue` changes

Replace the `<input>` (lines 27–33 today) with `<NameAutocomplete kind="..." v-model="name" @pick="onPick" />`. Add a `pick` event re-emitted upward alongside the existing `submit`. The submit-button row, realm combobox, and `canSubmit` logic are unchanged — manual submission still works for unsynced characters.

### `HomePage.vue` changes

Wire `@pick="onCharacterSubmit"` and `@pick="onGuildSubmit"` on the two `LookupForm` instances. The existing handlers already do `router.push` to the detail page with the same `{region, realm, name}` shape — no new logic required.

## Testing

### Backend (Pest feature tests)

- `tests/Feature/Endpoints/CharacterSuggestTest.php`
- `tests/Feature/Endpoints/GuildSuggestTest.php`

Following the existing `EndpointIntegrationTestCase` pattern. Cases:

- Prefix match ranks above substring match
- `num_of_searches DESC` tiebreak within a tier
- Alphabetical fallback when search counts are equal/null
- Case-insensitive query (`Mela` vs `mela`)
- Query < 2 chars (after trim) returns 200 with empty array
- Missing `q` returns 422
- Hard cap at 8 results
- Guild row with `faction = null` serializes cleanly
- Throttle returns 429 above the per-IP limit

Fixtures via existing `Character::factory()` and `Guild::factory()`.

### Frontend (Cypress)

Extend an existing homepage spec or add `home-autocomplete.cy.ts`:

- Seed (or rely on previously-synced) `melaniya` on `the-maelstrom` (EU). Type `mela` into the character input. Assert dropdown shows the row. Click → URL is `/character/eu/the-maelstrom/melaniya`.
- Same flow for a known guild (use a seeded one from `raiderio:seed`).
- Empty-state: type `zzzzzz`, assert "No matches" copy.

No unit tests for the FE component — no `vitest` script wired (per `frontend/CLAUDE.md`); Cypress covers the integration flow.

## Open questions

None at design time. Plan phase decides:
- Whether a faction icon component already exists or needs to be added.
- Whether `idx_characters_name` / `idx_guilds_name` BTREE indexes already exist or need migrations.
