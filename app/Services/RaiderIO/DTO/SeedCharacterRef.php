<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedCharacterRef
{
    public function __construct(
        public string $region,
        public string $realmSlug,
        public string $name,
    ) {}
}
