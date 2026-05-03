<?php

declare(strict_types=1);

return [
    'base_url' => env('RAIDERIO_BASE_URL', 'https://raider.io/api/v1'),

    'throttle' => [
        'per_minute' => (int) env('RAIDERIO_RATE_PER_MINUTE', 250),
    ],

    'regions' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RAIDERIO_SEED_REGIONS', 'eu,us'))
    ))),

    'season' => env('RAIDERIO_SEED_SEASON', 'season-mn-1'),

    'current_raid_tier' => env('RAIDERIO_CURRENT_RAID_TIER', 'tier-mn-1'),

    'phase' => [
        'guilds_per_region' => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
    ],

    'character_resync_ttl' => (int) env('RAIDERIO_SEED_CHAR_TTL', 12 * 3600),

    'teammate_crawl_during_seed' => (bool) env('RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED', false),

    'dispatch_chunk_size' => (int) env('RAIDERIO_SEED_CHUNK', 50),

    'dispatch_roster_character_syncs' => (bool) env('RAIDERIO_DISPATCH_ROSTER_CHAR_SYNCS', true),
];
