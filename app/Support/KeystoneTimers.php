<?php

declare(strict_types=1);

namespace App\Support;

final class KeystoneTimers
{
    /**
     * @param  list<array{upgrade_level: int, qualifying_duration: int}>|null  $keystoneUpgrades
     * @return int|null +1 timer in milliseconds
     */
    public static function plusOne(?array $keystoneUpgrades): ?int
    {
        foreach ($keystoneUpgrades ?? [] as $upgrade) {
            if (($upgrade['upgrade_level'] ?? null) === 1) {
                return (int) $upgrade['qualifying_duration'];
            }
        }

        return null;
    }
}
