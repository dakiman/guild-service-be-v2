<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameDataSeason;
use App\Support\Seasons;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off, idempotent tagging of rating_season_id for rows synced before
 * the column existed. Conservative by design: a rating is attributed to a
 * season only when the M+ slice was fetched after that season started AND
 * the character has a persisted run of that season (Blizzard reports the
 * newest-played season's rating, so a same-season run proves the rating is
 * that season's). Everything else stays NULL and is tagged on its next sync
 * (ratings:refresh drains untagged rows once enabled).
 */
class BackfillRatingSeason extends Command
{
    protected $signature = 'ratings:backfill-season
        {--dry-run : Count the rows that would be tagged without writing}
        {--chunk=50000 : Character id range per UPDATE statement}
        {--season= : Blizzard season id to tag (default: the current registry season)}';

    protected $description = 'Tag rating_season_id where provable: M+ slice synced since the season started and a dungeon run of that season exists for the character';

    /** Correlated EXISTS (no UPDATE … FROM) so the same SQL runs on SQLite in tests. */
    private const WHERE = <<<'SQL'
rating_season_id IS NULL
  AND mythic_plus_rating > 0
  AND mythics_synced_at >= ?
  AND EXISTS (
      SELECT 1 FROM dungeon_run_members m
      JOIN dungeon_runs r ON r.id = m.dungeon_run_id
      WHERE m.character_id = characters.id AND r.season = ?
  )
SQL;

    public function handle(): int
    {
        $seasonId = $this->option('season') !== null ? (int) $this->option('season') : Seasons::currentId();
        if ($seasonId === null) {
            $this->error('No current season in game_data_seasons — pass --season=<id>.');

            return self::FAILURE;
        }

        $startedAt = GameDataSeason::query()->whereKey($seasonId)->value('started_at');
        if ($startedAt === null) {
            $this->error("Season {$seasonId} is not in the registry or has no started_at.");

            return self::FAILURE;
        }
        $startedAt = Carbon::parse($startedAt)->format('Y-m-d H:i:s');

        if ($this->option('dry-run')) {
            $n = (int) DB::selectOne('SELECT COUNT(*) AS n FROM characters WHERE '.self::WHERE, [$startedAt, $seasonId])->n;
            $this->info("{$n} characters would be tagged with season {$seasonId}.");

            return self::SUCCESS;
        }

        $bounds = DB::selectOne('SELECT MIN(id) AS lo, MAX(id) AS hi FROM characters');
        if ($bounds->lo === null) {
            $this->info('No characters.');

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $total = 0;
        $start = microtime(true);
        for ($from = (int) $bounds->lo; $from <= (int) $bounds->hi; $from += $chunk) {
            $to = $from + $chunk - 1;
            $n = DB::update(
                'UPDATE characters SET rating_season_id = ? WHERE id BETWEEN ? AND ? AND '.self::WHERE,
                [$seasonId, $from, $to, $startedAt, $seasonId],
            );
            $total += $n;
            $this->line("  ids {$from}-{$to}: {$n} tagged");
        }
        $seconds = round(microtime(true) - $start, 1);

        $this->info("Tagged {$total} characters with season {$seasonId} in {$seconds}s.");
        Log::info('ratings:backfill-season', ['season_id' => $seasonId, 'tagged' => $total, 'seconds' => $seconds]);

        return self::SUCCESS;
    }
}
