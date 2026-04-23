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

    'regions' => ['eu', 'us', 'kr', 'tw'],

    /*
    |--------------------------------------------------------------------------
    | Staleness Thresholds (seconds)
    |--------------------------------------------------------------------------
    | How long before cached data is considered stale and a background
    | refresh is triggered.
    */

    'staleness' => [
        'character' => [
            'profile' => (int) env('BLIZZARD_STALE_CHARACTER_PROFILE', 900),
            'mythic_plus' => (int) env('BLIZZARD_STALE_CHARACTER_MYTHIC', 1800),
            'equipment' => (int) env('BLIZZARD_STALE_CHARACTER_EQUIPMENT', 900),
            'pvp' => (int) env('BLIZZARD_STALE_CHARACTER_PVP', 1800),
            'professions' => (int) env('BLIZZARD_STALE_CHARACTER_PROFESSIONS', 21600),
            'raids' => (int) env('BLIZZARD_STALE_CHARACTER_RAIDS', 3600),
        ],
        'guild' => [
            'basic' => (int) env('BLIZZARD_STALE_GUILD_BASIC', 3600),
            'roster' => (int) env('BLIZZARD_STALE_GUILD_ROSTER', 7200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Slice Sync Feature Flags
    |--------------------------------------------------------------------------
    | Each retail Full-sync slice can be individually disabled via env so a
    | misbehaving slice can be killed without a code revert.
    */

    'sync' => [
        'mythic_plus_enabled' => (bool) env('BLIZZARD_SYNC_MYTHIC_PLUS_ENABLED', true),
        'pvp_enabled' => (bool) env('BLIZZARD_SYNC_PVP_ENABLED', true),
        'professions_enabled' => (bool) env('BLIZZARD_SYNC_PROFESSIONS_ENABLED', true),
        'raids_enabled' => (bool) env('BLIZZARD_SYNC_RAIDS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    */

    'min_level_for_character_lookup' => (int) env('BLIZZARD_MIN_LEVEL_FOR_LOOKUP', 70),

    'mythic_plus' => [
        'season_override' => env('BLIZZARD_MYTHIC_SEASON_OVERRIDE'),
    ],

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
        'per_second' => 80,
        'per_hour' => 30000,
    ],

];
