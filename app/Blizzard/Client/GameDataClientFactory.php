<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use App\Blizzard\Contracts\TokenManagerInterface;

class GameDataClientFactory
{
    public function __construct(private readonly TokenManagerInterface $tokenManager) {}

    public function forRegion(string $region): BlizzardGameDataClient
    {
        return new BlizzardGameDataClient($this->tokenManager, $region);
    }
}
