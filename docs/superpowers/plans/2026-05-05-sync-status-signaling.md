# Sync Status Signaling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Backend explicitly signals when a character is mid-sync; frontend shows a syncing badge, polls at 5s, and shows per-section "syncing" messages instead of misleading "no data" text.

**Architecture:** Backend adds `sync_status` + `poll_after` to the meta response and sets `X-Sync-Status` + `Retry-After` headers. Frontend reads the signal, polls via a new composable, and section components inject freshness from character context to show appropriate empty states.

**Tech Stack:** Laravel 13 / PHP 8.4 / PHPUnit (backend), Vue 3 / TypeScript / TanStack Query / DaisyUI (frontend)

---

## File Structure

**Backend:**
- **Modify:** `app/Http/Resources/CharacterResource.php` — add `sync_status` and `poll_after` to meta
- **Modify:** `app/Http/Controllers/CharacterController.php` — add `X-Sync-Status` and `Retry-After` headers
- **Create:** `tests/Feature/Http/CharacterSyncStatusTest.php` — test the new meta fields and headers

**Frontend:**
- **Modify:** `src/types/character.ts` — add `sync_status`, `poll_after` to `MetaBlock`; add `isSyncing` to `CharacterLookupResult`
- **Modify:** `src/api/characters.ts` — read `X-Sync-Status` header
- **Create:** `src/composables/useSyncPolling.ts` — poll at 5s while syncing
- **Modify:** `src/composables/useCharacterContext.ts` — add `isSyncing` to context
- **Modify:** `src/pages/CharacterDetailLayout.vue` — wire up sync polling + badge
- **Create:** `src/components/feedback/SyncingBadge.vue` — visible syncing indicator
- **Modify:** `src/components/character/PvpRatingsCard.vue` — freshness-aware empty state
- **Modify:** `src/components/character/CharacterStatsCard.vue` — freshness-aware empty state
- **Modify:** `src/components/character/pve/RaidProgressionSection.vue` — freshness-aware empty state
- **Modify:** `src/components/character/pve/MythicPlusAllRuns.vue` — freshness-aware empty state

---

### Task 1: Backend — add sync_status to CharacterResource meta

**Files:**
- Modify: `app/Http/Resources/CharacterResource.php:69-99`
- Create: `tests/Feature/Http/CharacterSyncStatusTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterSyncStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_includes_syncing_status_when_slices_never_synced(): void
    {
        Character::factory()->create([
            'name' => 'newchar',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'mythics_synced_at' => null,
            'pvp_synced_at' => null,
            'stats_synced_at' => null,
        ]);

        $response = $this->getJson('/api/v1/characters/eu/tarren-mill/newchar');

        $response->assertOk();
        $response->assertJsonPath('meta.sync_status', 'syncing');
        $response->assertJsonPath('meta.poll_after', 5);
        $response->assertHeader('X-Sync-Status', 'syncing');
        $response->assertHeader('Retry-After', '5');
    }

    public function test_response_includes_complete_status_when_all_slices_synced(): void
    {
        Character::factory()->create([
            'name' => 'veteran',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'mythics_synced_at' => now(),
            'pvp_synced_at' => now(),
            'professions_synced_at' => now(),
            'raids_synced_at' => now(),
            'stats_synced_at' => now(),
            'titles_synced_at' => now(),
            'reputations_synced_at' => now(),
            'collections_synced_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/characters/eu/tarren-mill/veteran');

        $response->assertOk();
        $response->assertJsonPath('meta.sync_status', 'complete');
        $response->assertJsonMissing(['poll_after' => 5]);
        $response->assertHeaderMissing('X-Sync-Status');
    }

    public function test_sync_status_header_coexists_with_data_staleness_header(): void
    {
        Character::factory()->create([
            'name' => 'stalechar',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'game_version' => 'retail',
            'level' => 80,
            'updated_at' => now()->subHour(),
            'mythics_synced_at' => null,
        ]);

        $response = $this->getJson('/api/v1/characters/eu/tarren-mill/stalechar');

        $response->assertOk();
        $response->assertHeader('X-Sync-Status', 'syncing');
        $response->assertHeader('X-Data-Staleness', 'stale');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/CharacterSyncStatusTest.php`

Expected: All 3 tests FAIL because `meta.sync_status` doesn't exist yet and headers aren't set.

- [ ] **Step 3: Implement sync_status in CharacterResource::with()**

In `app/Http/Resources/CharacterResource.php`, replace the `with()` method body (lines 70–99) with:

```php
    public function with(Request $request): array
    {
        $freshness = [
            'profile' => $this->freshnessFor('updated_at', 'profile'),
            'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
            'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
            'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
            'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
            'stats' => $this->freshnessFor('stats_synced_at', 'stats'),
            'titles' => $this->freshnessFor('titles_synced_at', 'titles'),
            'reputations' => $this->freshnessFor('reputations_synced_at', 'reputations'),
            'collections' => $this->freshnessFor('collections_synced_at', 'collections'),
        ];

        if (config('blizzard.sync.achievements_enabled')) {
            $freshness['achievements'] = $this->freshnessFor('achievements_synced_at', 'achievements');
        }

        $isSyncing = in_array('never_synced', $freshness, true);

        $meta = [
            'game_version' => $this->game_version ?? 'retail',
            'forced_refresh' => false,
            'sync_status' => $isSyncing ? 'syncing' : 'complete',
            'freshness' => $freshness,
            'feature_flags' => [
                'achievements' => (bool) config('blizzard.sync.achievements_enabled'),
                'pets' => (bool) config('blizzard.sync.pets_enabled'),
            ],
        ];

        if ($isSyncing) {
            $meta['poll_after'] = 5;
        }

        return ['meta' => $meta];
    }
```

- [ ] **Step 4: Add X-Sync-Status header in CharacterController::show()**

In `app/Http/Controllers/CharacterController.php`, after line 49 (`$response->header('X-Data-Staleness', 'stale');`), before the `return $response;` on line 52, add the syncing header logic. The full block from line 46 onward becomes:

```php
        $response = (new CharacterResource($result))->response($request);

        if ($result->isStale()) {
            $response->header('X-Data-Staleness', 'stale');
        }

        if (in_array('never_synced', $response->getData(true)['meta']['freshness'] ?? [], true)) {
            $response->header('X-Sync-Status', 'syncing');
            $response->header('Retry-After', '5');
        }

        return $response;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/CharacterSyncStatusTest.php`

Expected: All 3 tests PASS.

- [ ] **Step 6: Run broader endpoint tests for regressions**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && docker compose exec app ./vendor/bin/phpunit tests/Feature/Http/`

Expected: All tests PASS.

- [ ] **Step 7: Run pint**

Run: `cd /home/dakiman/projects/guild-service-v2/backend && docker compose exec app ./vendor/bin/pint app/Http/Resources/CharacterResource.php app/Http/Controllers/CharacterController.php tests/Feature/Http/CharacterSyncStatusTest.php`

- [ ] **Step 8: Commit**

```bash
cd /home/dakiman/projects/guild-service-v2/backend
git add app/Http/Resources/CharacterResource.php app/Http/Controllers/CharacterController.php tests/Feature/Http/CharacterSyncStatusTest.php
git commit -m "feat(api): add sync_status meta field and X-Sync-Status header"
```

---

### Task 2: Frontend — types and API layer

**Files:**
- Modify: `src/types/character.ts:281-311`
- Modify: `src/api/characters.ts:32-41`

- [ ] **Step 1: Update MetaBlock type**

In `frontend/src/types/character.ts`, replace the `MetaBlock` interface (lines 281–300):

```typescript
export interface MetaBlock {
  game_version: GameVersion
  forced_refresh: boolean
  sync_status: 'syncing' | 'complete'
  poll_after?: number
  freshness: {
    profile: FreshnessState
    mythic_plus: FreshnessState
    pvp: FreshnessState
    professions: FreshnessState
    raids: FreshnessState
    stats: FreshnessState
    titles: FreshnessState
    reputations: FreshnessState
    collections: FreshnessState
    achievements?: FreshnessState
  }
  feature_flags: {
    achievements: boolean
    pets: boolean
  }
}
```

- [ ] **Step 2: Update CharacterLookupResult type**

In `frontend/src/types/character.ts`, replace the `CharacterLookupResult` interface (lines 307–311):

```typescript
export interface CharacterLookupResult {
  data: CharacterResource
  meta: MetaBlock
  isStale: boolean
  isSyncing: boolean
}
```

- [ ] **Step 3: Update fetchCharacter to read X-Sync-Status header**

In `frontend/src/api/characters.ts`, replace lines 32–41 (the return block after status checks):

```typescript
  const { data, meta } = res.data
  const headerStale = res.headers['x-data-staleness'] === 'stale'
  const metaStale = meta?.freshness?.profile === 'stale'
  const headerSyncing = res.headers['x-sync-status'] === 'syncing'

  return {
    data,
    meta,
    isStale: metaStale || headerStale,
    isSyncing: headerSyncing || meta?.sync_status === 'syncing',
  }
```

- [ ] **Step 4: Verify TypeScript compiles**

Run: `cd /home/dakiman/projects/guild-service-v2/frontend && npx vue-tsc --noEmit`

Expected: No type errors.

- [ ] **Step 5: Commit**

```bash
cd /home/dakiman/projects/guild-service-v2/frontend
git add src/types/character.ts src/api/characters.ts
git commit -m "feat(api): add isSyncing to character lookup result"
```

---

### Task 3: Frontend — useSyncPolling composable

**Files:**
- Create: `src/composables/useSyncPolling.ts`

- [ ] **Step 1: Create the composable**

Create `frontend/src/composables/useSyncPolling.ts`:

```typescript
import { watch, type ComputedRef, type Ref } from 'vue'
import { useQueryClient } from '@tanstack/vue-query'

export function useSyncPolling(
  isSyncing: ComputedRef<boolean> | Ref<boolean>,
  queryKey: () => readonly unknown[],
  intervalMs = 5_000,
) {
  const qc = useQueryClient()
  let timer: ReturnType<typeof setInterval> | null = null

  watch(
    isSyncing,
    (syncing) => {
      if (timer) {
        clearInterval(timer)
        timer = null
      }
      if (syncing) {
        timer = setInterval(() => qc.invalidateQueries({ queryKey: queryKey() }), intervalMs)
      }
    },
    { immediate: true },
  )
}
```

- [ ] **Step 2: Verify TypeScript compiles**

Run: `cd /home/dakiman/projects/guild-service-v2/frontend && npx vue-tsc --noEmit`

Expected: No type errors.

- [ ] **Step 3: Commit**

```bash
cd /home/dakiman/projects/guild-service-v2/frontend
git add src/composables/useSyncPolling.ts
git commit -m "feat: add useSyncPolling composable for 5s interval refetch"
```

---

### Task 4: Frontend — SyncingBadge component + layout wiring

**Files:**
- Create: `src/components/feedback/SyncingBadge.vue`
- Modify: `src/composables/useCharacterContext.ts`
- Modify: `src/pages/CharacterDetailLayout.vue`

- [ ] **Step 1: Create SyncingBadge component**

Create `frontend/src/components/feedback/SyncingBadge.vue`:

```vue
<template>
  <span class="badge badge-info gap-2">
    <span class="loading loading-spinner loading-xs" />
    Syncing data…
  </span>
</template>
```

- [ ] **Step 2: Add isSyncing to CharacterContext**

In `frontend/src/composables/useCharacterContext.ts`, add `isSyncing` to the interface. Replace the full file:

```typescript
import { inject, provide, type ComputedRef, type InjectionKey } from 'vue'
import type { CharacterResource, MetaBlock } from '@/types/character'

export interface CharacterContext {
  character: ComputedRef<CharacterResource>
  meta: ComputedRef<MetaBlock>
  freshness: ComputedRef<MetaBlock['freshness']>
  isStale: ComputedRef<boolean>
  isSyncing: ComputedRef<boolean>
  isClassic: ComputedRef<boolean>
  refetch: () => Promise<unknown>
}

export const CharacterContextKey: InjectionKey<CharacterContext> = Symbol('CharacterContext')

export function provideCharacterContext(ctx: CharacterContext) {
  provide(CharacterContextKey, ctx)
}

export function useCharacterContext(): CharacterContext {
  const ctx = inject(CharacterContextKey)
  if (!ctx) {
    throw new Error('useCharacterContext must be called inside <CharacterDetailLayout>')
  }
  return ctx
}
```

- [ ] **Step 3: Wire up layout**

In `frontend/src/pages/CharacterDetailLayout.vue`:

**Add imports** — after the `useStaleAutoRefresh` import (line 55), add:

```typescript
import { useSyncPolling } from '@/composables/useSyncPolling'
import SyncingBadge from '@/components/feedback/SyncingBadge.vue'
```

**Add isSyncing computed** — after line 83 (`const isStale = ...`), add:

```typescript
const isSyncing = computed(() => lookup.data.value?.isSyncing ?? false)
```

**Add useSyncPolling call** — after line 88 (`useStaleAutoRefresh(...)`), add:

```typescript
useSyncPolling(isSyncing, () => ['character', region.value, realm.value, name.value])
```

**Update provideCharacterContext** — add `isSyncing` to the provided context object (after `isStale,`):

```typescript
provideCharacterContext({
  character: character as ComputedRef<CharacterResource>,
  meta: meta as ComputedRef<MetaBlock>,
  freshness: computed(() => (meta.value ? meta.value.freshness : ({} as MetaBlock['freshness']))),
  isStale,
  isSyncing,
  isClassic,
  refetch: () => lookup.refetch(),
})
```

**Update template** — replace the `StaleBadge` line (line 17):

```vue
        <SyncingBadge v-if="isSyncing" />
        <StaleBadge v-else-if="isStale" :last-synced-at="character.synced_at ?? undefined" />
```

- [ ] **Step 4: Verify TypeScript compiles**

Run: `cd /home/dakiman/projects/guild-service-v2/frontend && npx vue-tsc --noEmit`

Expected: No type errors.

- [ ] **Step 5: Commit**

```bash
cd /home/dakiman/projects/guild-service-v2/frontend
git add src/components/feedback/SyncingBadge.vue src/composables/useCharacterContext.ts src/pages/CharacterDetailLayout.vue
git commit -m "feat: show SyncingBadge and poll at 5s while character is mid-sync"
```

---

### Task 5: Frontend — freshness-aware section components

**Files:**
- Modify: `src/components/character/PvpRatingsCard.vue`
- Modify: `src/components/character/CharacterStatsCard.vue`
- Modify: `src/components/character/pve/RaidProgressionSection.vue`
- Modify: `src/components/character/pve/MythicPlusAllRuns.vue`

- [ ] **Step 1: Update PvpRatingsCard**

Replace `frontend/src/components/character/PvpRatingsCard.vue` entirely:

```vue
<template>
  <div class="ma-card p-4">
    <h3 class="ma-text-heading text-sm uppercase tracking-wider mb-3">PvP</h3>
    <div v-if="isSyncingSlice" class="flex items-center gap-2 text-ma-muted/70 text-sm">
      <span class="loading loading-spinner loading-xs" />
      Syncing PvP data…
    </div>
    <div v-else-if="!brackets || brackets.length === 0" class="text-ma-muted/70 text-sm">
      No ranked PvP this season.
    </div>
    <ul v-else class="flex flex-col gap-2">
      <li
        v-for="bracket in sortedBrackets"
        :key="bracket.bracket"
        class="flex items-center justify-between text-sm"
      >
        <span class="text-ma-text">{{ formatBracket(bracket.bracket) }}</span>
        <div class="flex items-center gap-3">
          <span class="text-ma-gold tabular-nums font-bold">{{ bracket.rating }}</span>
          <span class="text-ma-muted/70 tabular-nums text-xs">
            {{ bracket.season.won }}–{{ bracket.season.lost }}
          </span>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useCharacterContext } from '@/composables/useCharacterContext'
import type { PvpBracketStats } from '@/types/character'

const props = defineProps<{
  brackets: PvpBracketStats[] | null
}>()

const { freshness } = useCharacterContext()
const isSyncingSlice = computed(() => freshness.value.pvp === 'never_synced')

const sortedBrackets = computed(() =>
  [...(props.brackets ?? [])].sort((a, b) => b.rating - a.rating)
)

function formatBracket(slug: string): string {
  if (slug === '2v2') return '2v2'
  if (slug === '3v3') return '3v3'
  if (slug === 'rbg') return 'RBG'
  if (slug.startsWith('blitz-')) {
    const parts = slug.slice(6).split('-')
    return 'Blitz ' + parts.map(p => p.charAt(0).toUpperCase() + p.slice(1)).join(' ')
  }
  return slug
}
</script>
```

- [ ] **Step 2: Update CharacterStatsCard**

In `frontend/src/components/character/CharacterStatsCard.vue`, add the context import and syncing check. Replace the `<script setup>` section and the empty-state template line.

Replace the full file:

```vue
<template>
  <div class="ma-card p-6">
    <h3 class="ma-text-heading mb-4 text-lg">Detailed stats</h3>

    <div v-if="isSyncingSlice" class="flex items-center gap-2 text-ma-muted/80 text-sm">
      <span class="loading loading-spinner loading-xs" />
      Syncing stats…
    </div>

    <div v-else-if="!stats" class="text-ma-muted/80 text-sm">
      Stats not available yet — refresh shortly.
    </div>

    <div v-else class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
      <StatRow label="Health" :value="formatInt(stats.health)" />
      <StatRow label="Primary" :value="primaryStatLabel" />
      <StatRow label="Crit" :value="formatPercent(stats.melee_crit?.value)" />
      <StatRow label="Haste" :value="formatPercent(stats.melee_haste?.value)" />
      <StatRow label="Mastery" :value="formatPercent(stats.mastery?.value)" />
      <StatRow label="Versatility" :value="versatilityLabel" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h } from 'vue'
import { useCharacterContext } from '@/composables/useCharacterContext'
import type { CharacterStats } from '@/types/character'

const props = defineProps<{ stats: CharacterStats | null }>()

const { freshness } = useCharacterContext()
const isSyncingSlice = computed(() => freshness.value.stats === 'never_synced')

const primaryStatLabel = computed(() => {
  if (!props.stats) return '—'
  const candidates: Array<{ label: string; entry?: { effective: number } }> = [
    { label: 'Strength', entry: props.stats.strength },
    { label: 'Agility', entry: props.stats.agility },
    { label: 'Intellect', entry: props.stats.intellect },
  ]
  const best = candidates
    .filter((c): c is { label: string; entry: { effective: number } } => c.entry !== undefined)
    .sort((a, b) => b.entry.effective - a.entry.effective)[0]
  return best ? `${best.label} ${formatInt(best.entry.effective)}` : '—'
})

const versatilityLabel = computed(() => {
  const damage = props.stats?.versatility_damage_done_bonus
  if (typeof damage !== 'number') return '—'
  return `${damage.toFixed(2)}%`
})

function formatInt(value: number | null | undefined): string {
  if (typeof value !== 'number') return '—'
  return new Intl.NumberFormat().format(Math.round(value))
}

function formatPercent(value: number | null | undefined): string {
  if (typeof value !== 'number') return '—'
  return `${value.toFixed(2)}%`
}

const StatRow = defineComponent({
  name: 'StatRow',
  props: { label: { type: String, required: true }, value: { type: String, required: true } },
  setup(slotProps) {
    return () =>
      h('div', { class: 'flex items-center justify-between' }, [
        h('span', { class: 'text-ma-muted/80' }, slotProps.label),
        h('span', { class: 'ma-text-heading font-medium' }, slotProps.value),
      ])
  },
})
</script>
```

- [ ] **Step 3: Update RaidProgressionSection**

In `frontend/src/components/character/pve/RaidProgressionSection.vue`, add syncing state before the "No raid data available" fallback.

Add import after line 53 (`import { useRaidInstances } from '@/composables/usePveGameData'`):

```typescript
import { useCharacterContext } from '@/composables/useCharacterContext'
```

Add after line 62 (`const showLegacy = ref(false)`):

```typescript
const { freshness } = useCharacterContext()
const isSyncingSlice = computed(() => freshness.value.raids === 'never_synced')
```

Replace the template line 21 (`<div v-else-if="!latestExpansion" ...>`):

```vue
    <div v-else-if="isSyncingSlice && !latestExpansion" class="ma-card p-6 text-sm text-ma-muted/70 flex items-center gap-2">
      <span class="loading loading-spinner loading-xs" />
      Syncing raid data…
    </div>
    <div v-else-if="!latestExpansion" class="ma-card p-6 text-sm text-ma-muted/70">
      No raid data available.
    </div>
```

- [ ] **Step 4: Update MythicPlusAllRuns**

In `frontend/src/components/character/pve/MythicPlusAllRuns.vue`, add syncing state before the "No mythic+ runs" fallback.

Add import in the `<script setup>` section:

```typescript
import { useCharacterContext } from '@/composables/useCharacterContext'
```

Add after existing setup declarations:

```typescript
const { freshness } = useCharacterContext()
const isSyncingSlice = computed(() => freshness.value.mythic_plus === 'never_synced')
```

Replace line 3 (`<div v-if="seasonRuns.length === 0" ...>`):

```vue
    <div v-if="isSyncingSlice && seasonRuns.length === 0" class="ma-card p-6 text-sm text-ma-muted/70 flex items-center gap-2">
      <span class="loading loading-spinner loading-xs" />
      Syncing dungeon data…
    </div>
    <div v-else-if="seasonRuns.length === 0" class="ma-card p-6 text-sm text-ma-muted/70">
      No mythic+ runs recorded this season.
    </div>
```

- [ ] **Step 5: Verify TypeScript compiles**

Run: `cd /home/dakiman/projects/guild-service-v2/frontend && npx vue-tsc --noEmit`

Expected: No type errors.

- [ ] **Step 6: Commit**

```bash
cd /home/dakiman/projects/guild-service-v2/frontend
git add src/components/character/PvpRatingsCard.vue src/components/character/CharacterStatsCard.vue src/components/character/pve/RaidProgressionSection.vue src/components/character/pve/MythicPlusAllRuns.vue
git commit -m "feat: show syncing state in section components when slice is never_synced"
```

---

### Task 6: Manual smoke test

- [ ] **Step 1: Build frontend**

Run: `cd /home/dakiman/projects/guild-service-v2/frontend && npm run build`

Expected: Build succeeds with no type errors.

- [ ] **Step 2: Test with a shallow character**

Open `http://100.82.124.39:8092/` in browser. Visit a character that has only been shallow-synced (or delete a character's slice timestamps in the database to simulate). Verify:
1. `SyncingBadge` appears ("Syncing data…" with spinner)
2. Section components show "Syncing {section}…" instead of "No data" messages
3. Page auto-refreshes every ~5 seconds
4. Once Full sync completes, badge disappears and data renders

- [ ] **Step 3: Test with a fully-synced character**

Visit a known fully-synced character (e.g., `melaniya` on `the-maelstrom`). Verify:
1. No `SyncingBadge` appears
2. All sections render normally
3. No excessive polling in network tab
