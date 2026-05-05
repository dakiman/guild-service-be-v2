# Shallow-to-Full Character Promotion

## Problem

Characters discovered via guild roster proactive syncs only receive Shallow depth (basic profile data). The only path to Full sync is a user visiting the guild page (`forceFanout=true`), leaving a large pool of max-level characters that never get their M+ data synced or trigger teammate discovery.

## Solution

Dispatch a Full sync with teammate crawl immediately after a Shallow sync completes, if the character qualifies.

## Qualification Criteria

Both must be true:
1. Character is max level (80)
2. Character has never been fully synced (`mythics_synced_at === null`)

## Implementation

**File**: `app/Blizzard/Jobs/SyncCharacterData.php`

**Location**: End of the Shallow persistence block (after character data is saved).

**Logic**:
```php
if ($character->level === 80 && $character->mythics_synced_at === null) {
    SyncCharacterData::dispatch(
        region: $this->region,
        realm: $this->realm,
        name: $this->name,
        depth: SyncDepth::Full,
        forceTeammateCrawl: true,
    );
}
```

## Design Decisions

- **`mythics_synced_at` as proxy**: Always set during Full sync, never set by Shallow/Standard. Clean indicator of "never fully synced."
- **Inline in handler**: No event/listener indirection needed for a simple conditional dispatch.
- **No TTL/delay**: The qualification check (`mythics_synced_at === null`) is a one-shot gate — once a character has been fully synced, it won't be re-promoted via this path. Subsequent Full re-syncs are handled by existing mechanisms (user visits, teammate crawl TTL, proactive sync).
- **`forceTeammateCrawl: true`**: The primary value of promoting these characters is discovering their M+ teammates, expanding the character graph.

## What This Replaces

The temporary reliance on user visits (`forceFanout=true`) as the only promotion path for shallow characters. With this change, every newly discovered max-level guild member automatically fans out into the full sync + teammate discovery pipeline.
