<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RaiderIO\DTO\SeedOptions;
use App\Services\RaiderIO\RaiderIOSeeder;
use Illuminate\Console\Command;

class RaiderIOSeed extends Command
{
    protected $signature = 'raiderio:seed
        {--phase= : guilds|runs|characters|all (only "guilds" implemented in phase 1)}
        {--limit= : Override per-phase limit (e.g., guilds_per_region)}
        {--regions= : Comma-separated region slugs (overrides config)}
        {--force : Bypass TTL gates}
        {--dry-run : Skip dispatches; report what would happen}';

    protected $description = 'Bootstrap the database from raider.io top-lists.';

    public function handle(RaiderIOSeeder $seeder): int
    {
        $phase = (string) $this->option('phase');
        $allowed = ['guilds', 'runs', 'characters', 'all'];

        if (! in_array($phase, $allowed, true)) {
            $this->error('Invalid --phase. Allowed: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        if ($phase === 'characters' || $phase === 'all') {
            $this->error("Phase '$phase' not yet implemented (phase 3 deliverable).");

            return self::FAILURE;
        }

        $opts = $this->buildOptions($phase);
        $report = match ($phase) {
            'guilds' => $seeder->seedGuilds($opts),
            'runs' => $seeder->seedRuns($opts),
        };

        $this->table(
            ['phase', 'regions', 'considered', 'dispatched', 'skipped_ttl', 'errors'],
            [[
                $report->phase,
                implode(',', $report->regions),
                $report->considered,
                $report->dispatched,
                $report->skippedTtl,
                $report->errors,
            ]]
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
