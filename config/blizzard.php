<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Blizzard API Credentials
    |--------------------------------------------------------------------------
    */

    'client' => [
        'id' => env('BLIZZARD_CLIENT_ID'),
        'secret' => env('BLIZZARD_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Regions
    |--------------------------------------------------------------------------
    */

    // TW dropped: Blizzard deprecated tw.battle.net (oauth returns 403);
    // APAC traffic is served via the KR region.
    'regions' => ['eu', 'us', 'kr'],

    /*
    |--------------------------------------------------------------------------
    | Staleness Thresholds (seconds)
    |--------------------------------------------------------------------------
    | How long before cached data is considered stale and a background
    | refresh is triggered.
    */

    'staleness' => [
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 604800),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 604800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 604800),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 604800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 604800),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 604800),
            'stats' => (int) env('BLIZZARD_STALE_CHARACTER_STATS', 604800),
            'titles' => (int) env('BLIZZARD_STALE_CHARACTER_TITLES', 604800),
            'reputations' => (int) env('BLIZZARD_STALE_CHARACTER_REPUTATIONS', 604800),
            'collections' => (int) env('BLIZZARD_STALE_CHARACTER_COLLECTIONS', 604800),
            'achievements' => (int) env('BLIZZARD_STALE_CHARACTER_ACHIEVEMENTS', 604800),
        ],
        'guild' => [
            'basic' => (int) env('BLIZZARD_STALE_GUILD_BASIC', 604800),
            'roster' => (int) env('BLIZZARD_STALE_GUILD_ROSTER', 604800),
        ],
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Per-Slice Sync Feature Flags
    |--------------------------------------------------------------------------
    | Plan 2 retail slices (mythic+, pvp, professions, raids) keep individual
    | kill-switch env flags. The Plan 4 slices (stats, titles, reputations,
    | collections, achievements) were removed in Plan 5 — they now run
    | unconditionally. To disable one of those, revert the slice in code.
    | Exception: achievements and pets were re-flagged (default false) due
    | to their significant disk cost (achievements alone ≈ 70 % of total DB).
    */

    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
        'teammate_crawl_enabled' => (bool) env('BLIZZARD_SYNC_TEAMMATE_CRAWL_ENABLED', false),
        'mounts_enabled' => (bool) env('BLIZZARD_SYNC_MOUNTS_ENABLED', false),
        'toys_enabled' => (bool) env('BLIZZARD_SYNC_TOYS_ENABLED', false),
        'achievements_enabled' => env('BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED', false),
        'pets_enabled' => env('BLIZZARD_SYNC_PETS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Teammate Crawl
    |--------------------------------------------------------------------------
    | Recursive fan-out from a Full-sync seed character to its Mythic+
    | teammates. `max_depth` = 0 disables fan-out (only the seed syncs).
    | `max_depth` = 1 dispatches one sync per direct teammate. Higher values
    | are clamped to 2 in the dispatch path; production should not exceed 1.
    | `recent_threshold` (seconds) skips teammates whose `updated_at` is fresher
    | than this window (same column `Character::isStale()` consults).
    */

    'crawl' => [
        'max_depth' => (int) env('BLIZZARD_CRAWL_MAX_DEPTH', 1),
        'recent_threshold' => (int) env('BLIZZARD_CRAWL_RECENT_THRESHOLD', 604800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    */

    'min_level_for_character_lookup' => (int) env('BLIZZARD_MIN_LEVEL_FOR_LOOKUP', 70),

    'endgame_level' => (int) env('BLIZZARD_ENDGAME_LEVEL', 90),

    'mythic_plus' => [
        'season_override' => env('BLIZZARD_MYTHIC_SEASON_OVERRIDE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs for Game Data Endpoints
    |--------------------------------------------------------------------------
    */

    'game_data_cache_ttl' => (int) env('BLIZZARD_GAME_DATA_CACHE_TTL', 86400 * 7),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeouts' => [
        'auth' => 10,
        'character_profile' => 15,
        'character_pool' => 20,
        'guild_roster' => 20,
        'game_data' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        // NOTE: BlizzardRateLimiter throttles JOBS, not HTTP requests, and one
        // job makes many Blizzard calls (a Full character sync ≈ 15-25). Blizzard's
        // ceiling is ~100 req/s, so the job rate must be budget ÷ avg-calls-per-job.
        // The old default of 80 jobs/s allowed >1000 req/s worst case. 10 keeps the
        // common Shallow/Standard mix comfortably under budget; tune via env for a
        // Full-heavy workload. (P2.4 — proper fix is to move throttling into the
        // HTTP client so it counts real requests.)
        'per_second' => (int) env('BLIZZARD_RATE_LIMIT_PER_SECOND', 10),
        'per_hour' => 30000,
        'circuit_breaker' => [
            'threshold' => (int) env('BLIZZARD_CIRCUIT_BREAKER_THRESHOLD', 10),
            'window' => 120,
            'cooldown' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth (Battle.net)
    |--------------------------------------------------------------------------
    | `redirect_uris` is a comma-separated allowlist; values not in this list
    | are rejected by `BlizzardOAuthRequest` / `BlizzardOAuthStateRequest`.
    | `state_ttl` controls how long a minted CSRF state is cached (seconds).
    */

    'oauth' => [
        'redirect_uris' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'BLIZZARD_OAUTH_REDIRECT_URIS',
                rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/blizzard-oauth'
            ))
        ))),
        'state_ttl' => (int) env('BLIZZARD_OAUTH_STATE_TTL', 600),
    ],

];
