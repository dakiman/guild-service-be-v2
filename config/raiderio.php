<?php

declare(strict_types=1);

return [
    'base_url' => env('RAIDERIO_BASE_URL', 'https://raider.io/api/v1'),

    // Optional API key from https://raider.io/settings/apps. When set, raider.io
    // unlocks higher request rates than the 200/min unauthenticated ceiling. The
    // throttle below is still applied locally — bump it up if you set a key.
    'access_key' => env('RAIDERIO_ACCESS_KEY'),

    'throttle' => [
        // Public ceiling per raider.io swagger is 200/min. Default leaves ~12% headroom.
        // If RAIDERIO_ACCESS_KEY is set you can safely raise this.
        'per_minute' => (int) env('RAIDERIO_RATE_PER_MINUTE', 175),
    ],

    'regions' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RAIDERIO_SEED_REGIONS', 'eu,us'))
    ))),

    'season' => env('RAIDERIO_SEED_SEASON', 'season-mn-1'),

    'current_raid_tier' => env('RAIDERIO_CURRENT_RAID_TIER', 'tier-mn-1'),

    'phase' => [
        'guilds_per_region' => (int) env('RAIDERIO_SEED_GUILDS_PER_REGION', 10),
        'runs_pages_per_region' => (int) env('RAIDERIO_SEED_RUNS_PAGES_PER_REGION', 5),
    ],

    'character_resync_ttl' => (int) env('RAIDERIO_SEED_CHAR_TTL', 24 * 3600),

    'teammate_crawl_during_seed' => (bool) env('RAIDERIO_SEED_TEAMMATE_CRAWL_ENABLED', false),

    'crawl' => [
        'enabled' => (bool) env('RAIDERIO_CRAWL_ENABLED', false),
    ],
];
