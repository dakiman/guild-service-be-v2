<?php

declare(strict_types=1);

namespace App\Enums;

enum Faction: string
{
    case Alliance = 'Alliance';
    case Horde = 'Horde';
    case Neutral = 'Neutral';
}
