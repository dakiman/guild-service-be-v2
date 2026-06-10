<?php

declare(strict_types=1);

namespace App\Blizzard\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

class BlizzardHealthCheck
{
    public function handle(object $job, Closure $next): void
    {
        if (Cache::get('blizzard:unhealthy')) {
            $job->release(60);

            return;
        }

        $next($job);
    }
}
