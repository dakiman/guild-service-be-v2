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
    | HTTP client
    |--------------------------------------------------------------------------
    | Retry backoff between attempts. An array of intervals keeps the total
    | attempt count fixed (3) while spacing retries out — a comma-separated
    | env string of milliseconds (e.g. "100,500"). Set "0,0" in tests to keep
    | the suite fast.
    */

    'http' => [
        'retry_backoff_ms' => array_map('intval', explode(',', (string) env('BLIZZARD_HTTP_RETRY_BACKOFF_MS', '100,500'))),
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
            // 24h (was 7d): M+ progress is the fastest-moving slice — players
            // push keys daily, and a week-stale rating/run list undercuts the
            // whole point of the M+ page.
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 86400),
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
    | Force-Refresh Cooldown (seconds)
    |--------------------------------------------------------------------------
    | `?refresh=1` grants a nonced, dedupe-bypassing sync at most once per this
    | window per entity (character or guild). Grant is claimed atomically via
    | Cache::add() in the controller (see App\Support\RefreshCooldown).
    */

    'refresh_cooldown' => (int) env('BLIZZARD_REFRESH_COOLDOWN', 300),

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
    | `max_teammates_per_seed` caps dispatches per seed: uncapped, one Full seed
    | fans out to every M+ teammate on record (dozens) — generation outran the
    | API budget and regrew a 34k-job backlog within an hour (2026-07-06).
    */

    'crawl' => [
        'max_depth' => (int) env('BLIZZARD_CRAWL_MAX_DEPTH', 1),
        'recent_threshold' => (int) env('BLIZZARD_CRAWL_RECENT_THRESHOLD', 604800),
        'max_teammates_per_seed' => (int) env('BLIZZARD_CRAWL_MAX_TEAMMATES_PER_SEED', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    */

    'endgame_level' => (int) env('BLIZZARD_ENDGAME_LEVEL', 90),

    'mythic_plus' => [
        'season_override' => env('BLIZZARD_MYTHIC_SEASON_OVERRIDE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mythic+ Ladder Ingestion
    |--------------------------------------------------------------------------
    | Drives the connected-realm keystone-leaderboard crawl that feeds the M+
    | meta hub. `regions`/`brackets` are comma-separated env strings; brackets
    | are the key-level floors each weekly aggregate is bucketed into.
    */

    'mplus_leaderboard' => [
        // Kill switch for the daily connected-realm ladder crawl.
        'enabled' => (bool) env('BLIZZARD_LADDER_ENABLED', false),
        'regions' => array_values(array_filter(explode(',', env('BLIZZARD_LADDER_REGIONS', 'eu,us')))),
        'brackets' => array_map('intval', array_values(array_filter(
            explode(',', env('BLIZZARD_LADDER_BRACKETS', '0,5,7,10,12')),
            fn ($v) => $v !== '',
        ))),
        'comp_min_sample' => (int) env('BLIZZARD_LADDER_COMP_MIN_SAMPLE', 25),
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
        // Request-level throttle (BlizzardHttpThrottle): one slot per real HTTP
        // request to api.blizzard.com, acquired via global request middleware —
        // covers request()-built calls, Http::pool() fan-outs, direct Http calls,
        // and 5xx retries alike. Blizzard allows ~100 req/s burst but only
        // 36,000 req/hour (= 10/s sustained); the default stays under that.
        // <= 0 disables the throttle (tests). Replaced the job-level throttle
        // that counted jobs, not calls, and flapped the circuit (2026-07-06).
        'requests_per_second' => (int) env('BLIZZARD_RATE_LIMIT_REQUESTS_PER_SECOND', 8),
        // How long a request may wait for a slot before the job is released.
        'block_seconds' => (int) env('BLIZZARD_RATE_LIMIT_BLOCK_SECONDS', 30),
        'circuit_breaker' => [
            'threshold' => (int) env('BLIZZARD_CIRCUIT_BREAKER_THRESHOLD', 10),
            'window' => 120,
            'cooldown' => 60,
        ],
    ],

    'roster_fanout' => [
        // Per-member fan-out dispatch rate. A 600-member cold guild spreads
        // over ~20 min at the default instead of landing at once — bounds the
        // burst so a guild visit can't monopolize the Blizzard rate budget or
        // churn jobs into their 6h retryUntil ceiling (2026-07-06 die-off).
        'jobs_per_minute' => (int) env('BLIZZARD_ROSTER_FANOUT_JOBS_PER_MINUTE', 30),
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
