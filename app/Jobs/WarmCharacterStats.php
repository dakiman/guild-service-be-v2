<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CharacterStatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WarmCharacterStats implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 290;

    /**
     * Must stay below Redis retry_after (180) or a second worker picks the
     * job up mid-run. One attempt only — a failed warm just means the cache
     * stays stale until the next hourly tick (or the next visitor's
     * dispatch-on-miss), and back-to-back retries of a long compute are
     * what ground the default queue today.
     */
    public int $timeout = 150;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(CharacterStatsService $service): void
    {
        $service->warm();
    }
}
