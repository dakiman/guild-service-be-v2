<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Jobs\SyncGuildData;
use App\Enums\SyncOrigin;
use App\Models\Guild;
use Illuminate\Console\Command;

class BackfillGuildShells extends Command
{
    protected $signature = 'guilds:backfill-shells {--limit=0 : Max dispatches, 0 = all} {--dry-run}';

    protected $description = 'One-off: dispatch a Discovery-lane SyncGuildData for guild shells that have never been synced';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $q = Guild::query()
            ->whereNull('roster_synced_at')
            ->orderBy('id');

        // count() ignores limit() (the LIMIT applies to the one-row aggregate),
        // so cap the reported number manually.
        $total = $q->count();
        if ($limit > 0) {
            $total = min($total, $limit);
            $q->limit($limit);
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] would dispatch {$total} guild syncs.");

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($q->cursor() as $guild) {
            SyncGuildData::dispatch(
                $guild->region,
                $guild->realm,
                $guild->name,
                origin: SyncOrigin::Discovery,
            );
            $n++;
        }

        $this->info("Dispatched {$n} guild syncs onto blizzard-background.");

        return self::SUCCESS;
    }
}
