<?php

declare(strict_types=1);

namespace App\Enums;

enum GameVersion: string
{
    case Retail = 'retail';
    case Classic = 'classic';

    public function profileNamespace(string $region): string
    {
        return match ($this) {
            self::Retail => "profile-{$region}",
            self::Classic => "profile-classic-{$region}",
        };
    }
}
