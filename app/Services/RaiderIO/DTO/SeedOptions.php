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
        public int $dungeonPages = 0,
        public int $maxGuildDispatches = 0,
        public int $maxCharDispatches = 0,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            regions: (array) config('raiderio.regions'),
            limit: (int) config('raiderio.phase.guilds_per_region'),
            teammateCrawl: (bool) config('raiderio.teammate_crawl_during_seed'),
            dungeonPages: (int) config('raiderio.phase.runs_pages_per_dungeon'),
            maxGuildDispatches: (int) config('raiderio.phase.max_guild_dispatches_per_region'),
            maxCharDispatches: (int) config('raiderio.phase.max_char_dispatches_per_region'),
        );
    }

    public function withOverrides(
        ?array $regions = null,
        ?int $limit = null,
        ?bool $force = null,
        ?bool $dryRun = null,
        ?bool $teammateCrawl = null,
        ?int $dungeonPages = null,
        ?int $maxGuildDispatches = null,
        ?int $maxCharDispatches = null,
    ): self {
        return new self(
            regions: $regions ?? $this->regions,
            limit: $limit ?? $this->limit,
            force: $force ?? $this->force,
            dryRun: $dryRun ?? $this->dryRun,
            teammateCrawl: $teammateCrawl ?? $this->teammateCrawl,
            dungeonPages: $dungeonPages ?? $this->dungeonPages,
            maxGuildDispatches: $maxGuildDispatches ?? $this->maxGuildDispatches,
            maxCharDispatches: $maxCharDispatches ?? $this->maxCharDispatches,
        );
    }
}
