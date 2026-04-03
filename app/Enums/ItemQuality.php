<?php

declare(strict_types=1);

namespace App\Enums;

enum ItemQuality: string
{
    case Poor = 'poor';
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Epic = 'epic';
    case Legendary = 'legendary';
    case Artifact = 'artifact';
}
