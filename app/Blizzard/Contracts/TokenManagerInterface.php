<?php

declare(strict_types=1);

namespace App\Blizzard\Contracts;

interface TokenManagerInterface
{
    public function getToken(string $region = 'eu'): string;

    public function refreshToken(string $region = 'eu'): string;
}
