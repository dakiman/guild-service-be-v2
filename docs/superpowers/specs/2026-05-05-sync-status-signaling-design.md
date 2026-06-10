# Sync Status Signaling Design

## Problem

When a user visits a shallow-synced character, the page renders with partial data. The character exists in the DB (so no 202 polling), but most slices have never been synced. The existing `useStaleAutoRefresh` only fires when profile-level staleness is detected (`X-Data-Staleness: stale`), which may not be set if the Shallow sync happened recently. The user sees misleading "No ranked PvP this season" / "No raid data" messages and has to manually refresh until the background Full sync completes.

## Solution

Backend explicitly signals when a character is mid-sync (has slices that have never been synced). Frontend reads the signal, shows a visible syncing badge, polls at 5s intervals, and shows appropriate "syncing" messages in section components instead of misleading "no data" messages.

## Backend Changes

### Signal computation

In `CharacterResource::with()`, after computing the `$freshness` array, derive a `sync_status` field:

- `"syncing"` — any slice in `$freshness` has value `"never_synced"`
- `"complete"` — all slices have been synced at least once

When `sync_status === "syncing"`, also include `poll_after: 5` (seconds) in the meta.

### Response shape

```json
{
  "data": { "..." },
  "meta": {
    "sync_status": "syncing",
    "poll_after": 5,
    "freshness": { "..." },
    "feature_flags": { "..." }
  }
}
```

When `sync_status === "complete"`, `poll_after` is omitted.

### HTTP headers

In `CharacterController::show()`, when the resource indicates syncing:

- `X-Sync-Status: syncing` header
- `Retry-After: 5` header (same value as `poll_after`)

These are set independently of `X-Data-Staleness` — both can be present simultaneously.

## Frontend Changes

### API layer (`src/api/characters.ts`)

Read the `X-Sync-Status` header from the response. Add `isSyncing: boolean` to the `CharacterLookupResult` interface (alongside `isStale`).

### Type updates (`src/types/character.ts`)

Add to `MetaBlock`:
- `sync_status: 'syncing' | 'complete'`
- `poll_after?: number`

Add to `CharacterLookupResult`:
- `isSyncing: boolean`

### Sync polling composable (`src/composables/useSyncPolling.ts`)

New composable, similar to `useStaleAutoRefresh` but with a shorter interval:

- Watches an `isSyncing` ref
- When true: invalidates the query every 5 seconds (reads `meta.poll_after` when available, defaults to 5)
- When false: clears the timer, stops polling
- Uses `queryClient.invalidateQueries()` to trigger re-fetch

### Character context (`src/composables/useCharacterContext.ts`)

Add `isSyncing: ComputedRef<boolean>` to `CharacterContext`. Provided by the layout alongside existing `isStale`.

### Layout (`src/pages/CharacterDetailLayout.vue`)

- Compute `isSyncing` from `lookup.data.value?.isSyncing ?? false`
- Call `useSyncPolling(isSyncing, ...)`
- Show `SyncingBadge` when `isSyncing === true` (takes visual precedence over `StaleBadge`)
- Provide `isSyncing` in character context

### SyncingBadge component (`src/components/feedback/SyncingBadge.vue`)

Similar to `StaleBadge` but with different messaging:
- DaisyUI `badge-info` style with a spinner
- Text: "Syncing data…"
- Shown instead of `StaleBadge` when `isSyncing` is true

### Section components

Components that show misleading empty-state messages need to inject freshness from `useCharacterContext` and show a syncing state when their slice is `never_synced`:

- `PvpRatingsCard.vue` — currently shows "No ranked PvP this season." when `brackets` is null. Should show "Syncing PvP data…" with spinner when `freshness.pvp === 'never_synced'`.
- `CharacterStatsCard.vue` — currently shows "Stats not available yet — refresh shortly." Should show "Syncing stats…" with spinner when `freshness.stats === 'never_synced'`.
- `pve/RaidProgressionSection.vue` — currently shows "No raid data available." Should show "Syncing raid data…" when `freshness.raids === 'never_synced'`.
- `pve/MythicPlusAllRuns.vue` — currently shows "No mythic+ runs recorded this season." Should show "Syncing dungeon data…" when `freshness.mythic_plus === 'never_synced'`.

Pattern: inject `useCharacterContext()`, check `freshness.<slice> === 'never_synced'`, show spinner + "Syncing {section}…" instead of the "No data" message. The existing "No data" message remains for when the slice IS synced but genuinely empty (`freshness !== 'never_synced'` and data is null/empty).

## Decisions

- **Frontend derives nothing** — `sync_status` and `poll_after` come from the backend. Frontend is a dumb consumer.
- **5-second polling** — aggressive since user is actively watching. Stops immediately when `sync_status` flips to `"complete"`.
- **No max retry cap** — polling continues as long as the backend says `"syncing"`. If the sync fails and the character never completes, the per-slice timestamps stay null and the backend keeps returning `"syncing"`. This is acceptable because the Full sync will be retried by the queue system.
- **`SyncingBadge` takes precedence over `StaleBadge`** — if both conditions are true, only show syncing (it subsumes staleness semantically).
- **Provide/inject for freshness** — components use `useCharacterContext()` to access freshness state rather than receiving it as props.
