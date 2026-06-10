<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final readonly class SeedOptions
{
    /**
     * @param  list<string>  $regions
     */
    public function __construct(
        public array $regions,
        public int $limit,
        public bool $force = false,
        public bool $dryRun = false,
        public bool $teammateCrawl = false,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            regions: (array) config('raiderio.regions'),
            limit: (int) config('raiderio.phase.guilds_per_region'),
            teammateCrawl: (bool) config('raiderio.teammate_crawl_during_seed'),
        );
    }

    public function withOverrides(
        ?array $regions = null,
        ?int $limit = null,
        ?bool $force = null,
        ?bool $dryRun = null,
        ?bool $teammateCrawl = null,
    ): self {
        return new self(
            regions: $regions ?? $this->regions,
            limit: $limit ?? $this->limit,
            force: $force ?? $this->force,
            dryRun: $dryRun ?? $this->dryRun,
            teammateCrawl: $teammateCrawl ?? $this->teammateCrawl,
        );
    }
}
