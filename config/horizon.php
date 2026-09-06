<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        //
    ],

    'environments' => [
        'production' => [
            'blizzard-auth' => [
                'connection' => 'redis',
                'queue' => ['blizzard-auth'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 30,
                'nice' => 0,
            ],
            'blizzard-user-sync' => [
                'connection' => 'redis',
                'queue' => ['blizzard-user-sync'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,
                'maxProcesses' => 5,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            // The single blizzard-background supervisor used to balance 'auto' over
            // roster fan-out, the refresh/proactive lane, and ladder seeding — in
            // practice keeping 6 of 8 workers on the two mostly-idle queues while the
            // hot background queue got 2-3. It also queued teammate crawls behind the
            // daily refresh batch on the same FIFO queue, so a Full-heavy stretch made
            // the day's Shallows run late (and expire), while crawls sitting behind a
            // full day of Shallows expired at their 24h retryUntil unrun (3,500+
            // expired on 2026-09-05). Split into three explicit supervisors so the
            // refresh/proactive lane, best-effort crawls, and bursty fan-out/ladder
            // work each get a dedicated, correctly-sized pool.
            'blizzard-background' => [
                'connection' => 'redis',
                'queue' => ['blizzard-background'],
                'balance' => 'simple',
                'minProcesses' => 4,
                'maxProcesses' => 4,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            'blizzard-crawl' => [
                'connection' => 'redis',
                'queue' => ['blizzard-crawl'],
                'balance' => 'simple',
                'minProcesses' => 2,
                'maxProcesses' => 2,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            'blizzard-fanout' => [
                'connection' => 'redis',
                'queue' => ['blizzard-roster-sync', 'blizzard-ladder'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            'default-worker' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 150,
            ],
            'raiderio-crawl' => [
                'connection' => 'redis',
                'queue' => ['raiderio-crawl'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 5,
                'timeout' => 120,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 128,
                'nice' => 0,
            ],
        ],

        'local' => [
            'blizzard-auth' => [
                'connection' => 'redis',
                'queue' => ['blizzard-auth'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 30,
                'nice' => 0,
            ],
            'blizzard-user-sync' => [
                'connection' => 'redis',
                'queue' => ['blizzard-user-sync'],
                'balance' => 'simple',
                'minProcesses' => 3,
                'maxProcesses' => 5,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            // See the production section above for why blizzard-background was split
            // into three explicit supervisors (FIFO starvation of crawls behind the
            // refresh batch on 2026-09-05; auto-balance left the hot queue
            // under-served).
            'blizzard-background' => [
                'connection' => 'redis',
                'queue' => ['blizzard-background'],
                'balance' => 'simple',
                'minProcesses' => 4,
                'maxProcesses' => 4,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            'blizzard-crawl' => [
                'connection' => 'redis',
                'queue' => ['blizzard-crawl'],
                'balance' => 'simple',
                'minProcesses' => 2,
                'maxProcesses' => 2,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            'blizzard-fanout' => [
                'connection' => 'redis',
                'queue' => ['blizzard-roster-sync', 'blizzard-ladder'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],
            'default-worker' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 150,
            ],
            'raiderio-crawl' => [
                'connection' => 'redis',
                'queue' => ['raiderio-crawl'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 5,
                'timeout' => 120,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 128,
                'nice' => 0,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'admin_emails' => explode(',', env('HORIZON_ADMIN_EMAILS', '')),

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
