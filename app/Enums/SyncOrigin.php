<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Declares WHY a Blizzard sync job (character or guild) was dispatched, and therefore which
 * Horizon queue lane it belongs on. Routing must never be inferred from
 * other params — a crawlDepth-based inference is how roster fan-out flooded
 * the user-facing queue (2026-07-06 incident; see
 * docs/superpowers/specs/2026-07-06-sync-lanes-pending-ux-design.md).
 */
enum SyncOrigin: string
{
    case UserLookup = 'user-lookup';
    case RosterFanout = 'roster-fanout';
    case TeammateCrawl = 'teammate-crawl';
    case Proactive = 'proactive';
    // Discovered rather than requested: guild shells created as a side effect
    // of a character sync, shell backfills, and the raider.io discovery seed's
    // character/guild dispatches — background lane, never the user lane.
    case Discovery = 'discovery';

    public function queue(): string
    {
        return match ($this) {
            self::UserLookup => 'blizzard-user-sync',
            self::RosterFanout => 'blizzard-roster-sync',
            self::TeammateCrawl, self::Proactive, self::Discovery => 'blizzard-background',
        };
    }
}
