<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncDepth: string
{
    case Shallow = 'shallow';
    case Standard = 'standard';
    case Full = 'full';
}
