<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncDepth: string
{
    case Shallow = 'shallow';
    case Standard = 'standard';
    case Full = 'full';
    case StaleOnly = 'stale-only'; // Standard body + only slices stale AT EXECUTION TIME

    public function syncsSlices(): bool
    {
        return $this === self::Full || $this === self::StaleOnly;
    }
}
