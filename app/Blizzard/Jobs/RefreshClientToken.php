<?php

declare(strict_types=1);

namespace App\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshClientToken implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('blizzard-auth');
    }

    public function handle(TokenManagerInterface $tokenManager): void
    {
        $regions = config('blizzard.regions', ['eu', 'us', 'kr']);

        foreach ($regions as $region) {
            $tokenManager->refreshToken($region);

            Log::info("Blizzard client token refreshed for region: {$region}");
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('RefreshClientToken failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
