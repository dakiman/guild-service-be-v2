<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillJsonbSlices extends Command
{
    protected $signature = 'characters:backfill-jsonb-slices {--chunk=500 : Characters per batch} {--dry-run : Count without writing}';

    protected $description = 'One-off: copy character_titles/character_reputations rows into the characters.title_ids/reputations JSONB columns';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $targets = Character::query()
            ->where(function ($q) {
                $q->whereNotNull('titles_synced_at')->orWhereNotNull('reputations_synced_at');
            })
            // Resumable: both columns are written together, so either being
            // set means this character is done.
            ->whereNull('title_ids')
            ->whereNull('reputations');

        $total = $targets->count();

        if ($dryRun) {
            $this->info("[dry-run] {$total} characters need JSONB backfill.");

            return self::SUCCESS;
        }

        $done = 0;
        $targets->select('id')->chunkById($chunk, function ($characters) use (&$done, $total) {
            $ids = $characters->pluck('id')->all();

            $titles = DB::table('character_titles')
                ->whereIn('character_id', $ids)
                ->orderBy('title_id')
                ->get()
                ->groupBy('character_id');

            $reps = DB::table('character_reputations')
                ->whereIn('character_id', $ids)
                ->orderBy('faction_id')
                ->get()
                ->groupBy('character_id');

            foreach ($ids as $id) {
                $titleIds = ($titles->get($id) ?? collect())
                    ->pluck('title_id')
                    ->map(fn ($v) => (int) $v)
                    ->values();

                $repRows = ($reps->get($id) ?? collect())
                    ->map(fn ($row) => [
                        'faction_id' => (int) $row->faction_id,
                        'faction_name' => $row->faction_name,
                        'standing' => $row->standing,
                        'value' => (int) $row->value,
                        'max' => (int) $row->max,
                    ])
                    ->values();

                // DB::table update: never bumps updated_at, the profile-sync clock.
                DB::table('characters')->where('id', $id)->update([
                    'title_ids' => $titleIds->toJson(),
                    'reputations' => $repRows->toJson(),
                ]);
            }

            $done += count($ids);
            if ($done % 25000 < count($ids)) {
                $this->info("{$done}/{$total}");
            }
        });

        $this->info("Backfilled {$done} characters (of {$total} matched).");

        return self::SUCCESS;
    }
}
