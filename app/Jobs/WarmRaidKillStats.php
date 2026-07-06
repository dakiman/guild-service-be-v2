<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RaidKillStatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WarmRaidKillStats implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 1740;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(RaidKillStatsService $service): void
    {
        $service->warm();
    }
}
