<?php

declare(strict_types=1);

namespace App\Enums;

enum Region: string
{
    case EU = 'eu';
    case US = 'us';
    case KR = 'kr';
    case TW = 'tw';

    public function apiBaseUrl(): string
    {
        return "https://{$this->value}.api.blizzard.com";
    }

    public function oauthBaseUrl(): string
    {
        return match ($this) {
            self::KR, self::TW => 'https://apac.battle.net',
            default => "https://{$this->value}.battle.net",
        };
    }
}
