# Guild roster: FK wiring + user-visit cascade

**Status:** Approved 2026-05-03. Implementation plan to follow.

## Problem

Two coupled gaps make the guild roster page (commit `0451ed9` on `feat/guild-roster-redesign`) ship empty data for most members:

1. **`guild_members.character_id` is left NULL** even when a matching `characters` row exists. The eager-load path in `GuildController::show` (`->with(['character:id,equipped_item_level,...'])`) misses, so iLvl/M+/spec columns are blank. A stitch-by-tuple workaround (one extra query per page) was added in the same commit; it's correct but masks the real bug.
2. **No user-visit cascade.** Visiting a guild dispatches `SyncGuildData` (when missing/stale) → `SyncGuildRoster`, which only dispatches `SyncCharacterData::Shallow` per member by default. Full + teammate crawl are gated behind `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS` and `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED` (both default false; only the seeder forces them on). So even with FK wired correctly, the eager-loaded character rows have no M+ rating / spec / equipped iLvl.

Together: a user visits a guild, sees a roster of names, no per-member data, and no chain pull happens.

## Goals

- **Forward-only fix**: no backfill commands, no migrations. `php artisan migrate:fresh` clears stale rows; the new write paths set `character_id` going forward.
- Both write directions (roster→character, character→roster) populate `character_id` when the matching row exists.
- A user visiting a guild triggers a full cascade — Full per-member sync + M+ teammate crawl — but with TTL gates so repeat visits within the freshness window are near-no-ops.
- Background `ProactiveSyncGuilds` stays cheap (Shallow only, no crawl) — same as today.

## Non-goals

- No backfill / repair artisan command. DB is disposable pre-prod.
- No FE changes. The roster columns light up automatically as syncs land; reload-to-refresh is acceptable UX for now.
- No change to staleness model on `Guild` itself — the outer gate (`Guild::isStale()`) stays as-is.
- Not promoting `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS` to default-true. The cascade is per-dispatch override (mirrors how the seeder threads `forceFanout`).

## Design

### Part 1 — FK wiring (both directions)

**Roster → Character** in `SyncGuildData::handle()` (around line 100-126):

Before the `GuildMember::upsert(...)` call, do one bulk lookup for the roster's `(name, realm)` tuples, scoped to the guild's region and `game_version='retail'`:

```php
$tuples = collect($members)->map(fn($m) => ['name' => $m->name, 'realm' => $m->realm]);
$charsByTuple = Character::query()
    ->where('region', $this->region)
    ->where('game_version', 'retail')
    ->where(function ($q) use ($tuples) {
        foreach ($tuples as $t) {
            $q->orWhere(fn($qq) => $qq->where('name', $t['name'])->where('realm', $t['realm']));
        }
    })
    ->get(['id', 'name', 'realm'])
    ->keyBy(fn($c) => "{$c->name}|{$c->realm}");
```

Then in the `$memberRecords[]` loop, set `'character_id' => $charsByTuple["{$member->name}|{$member->realm}"]?->id ?? null`. Add `'character_id'` to the `GuildMember::upsert` update column list so re-runs fill it in for rows where the Character was created later.

**Character → Roster** in `SyncCharacterData::handle()`, immediately after the `Character::updateOrCreate(...)` (around line 209-217), before the guild-link block:

```php
GuildMember::query()
    ->where('name', $this->name)
    ->where('realm', $this->realm)
    ->whereNull('character_id')
    ->whereHas('guild', fn($q) => $q->where('region', $this->region))
    ->update(['character_id' => $character->id]);
```

The `whereNull` keeps it idempotent. The `guild.region` constraint prevents cross-region collisions on shared realm names. One UPDATE per character sync. There's no standalone index on `guild_members.name` today (the unique key is `(guild_id, name, realm)`); at current row counts this is a non-issue, but if `guild_members` grows beyond ~1M rows, add an index on `(name, realm)` — out of scope for this spec.

**Remove the stitch block** in `GuildController::show` (lines 54-86): the eager load on line 44 takes over once both write paths are live. Drop the now-unused `App\Models\Character` import on line 13.

### Part 2 — User-visit cascade with TTL gates

**Thread `forceCascade` through the job chain** (mirrors the seeder's `forceRosterFanout` plumbing):

- `GuildController::show` → `SyncGuildData::dispatch($region, $realm, $guild, forceCascade: true)`.
- New `bool $forceCascade = false` constructor param on `SyncGuildData` (non-readonly with property default for old-shape unserialize, same pattern as `forceRosterFanout`/`forceTeammateCrawl`).
- `SyncGuildData::handle()` passes `SyncGuildRoster::dispatch($guild, forceFanout: $this->forceCascade || $this->forceRosterFanout)`.
- `SyncGuildRoster::handle()` — when `forceFanout` is true, also passes `forceTeammateCrawl: true` to each Full dispatch.

**Unify the TTL gate at 24h.** Today only the Full dispatch loop checks `Character.updated_at`; the Shallow loop fires unconditionally. Refactor `SyncGuildRoster::handle()` to compute the freshness map once and gate **both** dispatch loops:

```php
$ttl = (int) config('raiderio.character_resync_ttl', 86400); // bump default
$cutoff = now()->subSeconds($ttl);
$freshIds = Character::query()
    ->where('region', $this->guild->region)
    ->where('game_version', 'retail')
    ->where('updated_at', '>', $cutoff)
    ->whereIn(/* (name, realm) tuples */)
    ->get(['name', 'realm'])
    ->mapWithKeys(fn($c) => ["{$c->name}|{$c->realm}" => true]);

foreach ($members as $member) {
    if (isset($freshIds["{$member->name}|{$member->realm}"])) {
        continue;
    }
    SyncCharacterData::dispatch(... Shallow ...);
}
```

Same skip applied to the Full loop in `dispatchFullSyncsForMembers()`. Result: a member with a fresh Character row gets neither Shallow nor Full dispatched on a guild visit.

**Env defaults:**
- `RAIDERIO_SEED_CHAR_TTL=86400` (24h, was 43200 = 12h) — gates roster fan-out (Shallow + Full).
- `BLIZZARD_CRAWL_RECENT_THRESHOLD=259200` (3 days, was 21600 = 6h) — gates the teammate crawl dispatches inside `dispatchTeammateCrawl()`. Crawled descendants are typically less central to the page than direct roster members, so they refresh slower.

Global flags stay default-false:
- `RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS=false` — `ProactiveSyncGuilds` stays Shallow only.
- `BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED=false` — non-seed, non-user-visit Full syncs (e.g., the per-character lookup path) don't trigger crawls.

### Net behavior

| Trigger | Roster fan-out | Teammate crawl |
|---|---|---|
| User visits guild (missing/stale) | Full per cold member | Yes, depth-2 capped |
| User visits guild (fresh) | No `SyncGuildData` dispatch at all | — |
| Same user revisits within 24h | Shallow + Full both skipped per fresh member | — |
| `ProactiveSyncGuilds` (tier 1/2) | Shallow only (today's behavior) | No |
| Seeder (`raiderio:seed`) | Full per cold member (today's behavior) | Yes (today's behavior) |

## Tests

Factories only, no live Blizzard. New files:

1. `tests/Feature/Blizzard/Jobs/SyncGuildDataLinksExistingCharacterTest.php` — pre-seed a `Character` matching one roster member; fake the profile client roster response; assert the matching `GuildMember.character_id` is set, an unrelated unmatched member's `character_id` is NULL. Re-run after creating the previously-missing character; assert `character_id` is now set on the existing GuildMember row (covers `character_id` in upsert update column list).

2. `tests/Unit/Blizzard/Jobs/SyncCharacterDataLinksGuildMemberTest.php` — pre-seed `Guild` (region=eu) + `GuildMember(character_id=null)`; stub the profile client to return a Standard-depth profile for `(name, realm)`; run the job; assert `GuildMember.character_id` now equals the upserted Character's id. Edge case: pre-seed a second guild in `region=us` with a same-name `GuildMember`; assert that one is NOT linked.

3. `tests/Feature/Blizzard/Jobs/SyncGuildRosterTtlGateTest.php` — extend the existing `SyncGuildRosterCharacterFanoutTest` pattern. Pre-seed a roster of 3 members: one with no Character row, one with a Character `updated_at = now()`, one with a Character `updated_at = now()->subHours(25)`. Dispatch `SyncGuildRoster` with `forceFanout: true`. Assert: `SyncCharacterData` dispatched (Shallow + Full) for the cold member and the stale member; nothing dispatched for the fresh member. Verifies the unified TTL gate covers both Shallow and Full loops.

4. `tests/Feature/Blizzard/Jobs/SyncGuildDataForceCascadeTest.php` — dispatch `SyncGuildData::dispatch($region, $realm, $name, forceCascade: true)`; assert `SyncGuildRoster` was dispatched with `forceFanout=true`; transitively assert the per-member `SyncCharacterData::Full` dispatches carry `forceTeammateCrawl=true`. Mirror with `forceCascade: false` to verify the proactive path stays cheap.

5. `tests/Feature/GuildControllerEagerLoadTest.php` (or extend an existing controller test) — seed a Guild + 50 GuildMembers with `character_id` populated via factory; assert the response payload exposes iLvl/M+/spec on those members (eager load works without the stitch block).

## Rollout

```bash
cd backend
php artisan migrate:fresh
# Optional: re-seed for visible roster data
php artisan raiderio:seed --phase=guilds --limit=10
```

Set the new env defaults in `.env` (or rely on config defaults):
```
RAIDERIO_SEED_CHAR_TTL=86400
BLIZZARD_CRAWL_RECENT_THRESHOLD=259200
```

Restart Horizon (per `backend/CLAUDE.md`: required after job edits because `PHP_OPCACHE_VALIDATE_TIMESTAMPS=0` in the container):
```bash
docker compose restart horizon
```

## Risks

- **First user visit to a popular guild** queues N×Full + N×crawl jobs. With `BlizzardRateLimiter` at 80/s and a 500-member guild + depth-2 crawl, the queue can hold thousands of jobs and take 10+ minutes to drain. The user sees a partial roster on first load, full data after refresh. Acceptable pre-prod; revisit if/when traffic appears.
- **`ShouldBeUnique` 60s window** dedupes within-burst dispatches but not across guild visits 60s+ apart. The 24h TTL gate is the actual repeat-visit guard.
- **Cross-region collisions** on the Character→Roster backfill: avoided by the `whereHas('guild', region=...)` constraint. Confirmed in test #2.
