<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\DTO\SeedReport;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Console\Command;

class RaiderIOSeed extends Command
{
    protected $signature = 'raiderio:seed
        {--phase= : guilds|runs|all (Phase 3 / characters cancelled — no public raider.io endpoint)}
        {--limit= : Override per-phase limit (guilds: guilds-per-region; runs: pages-per-region)}
        {--regions= : Comma-separated region slugs (overrides config)}
        {--force : Bypass TTL gates}
        {--dry-run : Skip dispatches; report what would happen}';

    protected $description = 'Bootstrap the database from raider.io top-lists.';

    public function handle(RaiderIOSeeder $seeder): int
    {
        $phase = (string) $this->option('phase');
        $allowed = ['guilds', 'runs', 'all'];

        if (! in_array($phase, $allowed, true)) {
            $this->error('Invalid --phase. Allowed: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        $reports = match ($phase) {
            'guilds' => [$seeder->seedGuilds($this->buildOptions('guilds'))],
            'runs' => [$seeder->seedRuns($this->buildOptions('runs'))],
            'all' => [
                $seeder->seedGuilds($this->buildOptions('guilds')),
                $seeder->seedRuns($this->buildOptions('runs')),
            ],
        };

        $this->table(
            ['phase', 'regions', 'considered', 'dispatched', 'skipped_ttl', 'skipped_dedupe', 'errors'],
            array_map(fn (SeedReport $r) => [
                $r->phase,
                implode(',', $r->regions),
                $r->considered,
                $r->dispatched,
                $r->skippedTtl,
                $r->skippedDedupe,
                $r->errors,
            ], $reports)
        );

        return self::SUCCESS;
    }

    protected function buildOptions(string $phase): SeedOptions
    {
        $regions = $this->option('regions')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('regions')))))
            : (array) config('raiderio.regions');

        $defaultLimit = match ($phase) {
            'runs' => (int) config('raiderio.phase.runs_pages_per_region'),
            default => (int) config('raiderio.phase.guilds_per_region'),
        };
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : $defaultLimit;

        return new SeedOptions(
            regions: $regions,
            limit: $limit,
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
            teammateCrawl: (bool) config('raiderio.teammate_crawl_during_seed'),
        );
    }
}
