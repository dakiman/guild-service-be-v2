<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Console\Command;

class BackfillSlices extends Command
{
    protected $signature = 'blizzard:backfill-slices {--limit=200}';

    protected $description = 'Dispatch Full sync for retail characters with any null slice timestamp';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $q = Character::query()
            ->where('game_version', 'retail')
            ->where(function ($q) {
                $q->whereNull('mythics_synced_at')
                    ->orWhereNull('pvp_synced_at')
                    ->orWhereNull('professions_synced_at')
                    ->orWhereNull('raids_synced_at')
                    ->orWhereNull('stats_synced_at')
                    ->orWhereNull('titles_synced_at')
                    ->orWhereNull('reputations_synced_at')
                    ->orWhereNull('collections_synced_at');
            })
            ->orderByDesc('num_of_searches')
            ->limit($limit);

        $n = 0;
        foreach ($q->cursor() as $c) {
            SyncCharacterData::dispatch($c->region, $c->realm, $c->name, SyncDepth::Full);
            $n++;
        }

        $this->info("Dispatched {$n} Full syncs.");

        return self::SUCCESS;
    }
}
