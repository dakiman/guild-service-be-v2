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

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(CharacterStatsService $service): void
    {
        $service->warm();
    }
}
