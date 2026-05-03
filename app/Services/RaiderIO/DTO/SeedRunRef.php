<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedRunRef
{
    /**
     * @param  list<SeedCharacterRef>  $members
     */
    public function __construct(
        public int $keystoneRunId,
        public string $region,
        public array $members,
    ) {}
}
