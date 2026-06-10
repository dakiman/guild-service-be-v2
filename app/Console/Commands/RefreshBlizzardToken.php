<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Console\Command;

class RefreshBlizzardToken extends Command
{
    protected $signature = 'blizzard:token';

    protected $description = 'Refresh Blizzard API client credentials token for all configured regions';

    public function handle(TokenManagerInterface $tokenManager): int
    {
        $regions = config('blizzard.regions', ['eu', 'us', 'kr', 'tw']);

        foreach ($regions as $region) {
            $tokenManager->refreshToken($region);
            $this->info("Token refreshed for region: {$region}");
        }

        $this->info('All Blizzard tokens refreshed successfully.');

        return self::SUCCESS;
    }
}
