<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Models\LadderRun;
use App\Services\Ranks\RankMaterializer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshRatings extends Command
{
    protected $signature = 'ratings:refresh {--cap= : Override blizzard.rating_refresh.daily_cap}';

    protected $description = 'Shallow-resync rated endgame characters whose rating predates the current season (ladder members first)';

    public function handle(RankMaterializer $ranks): int
    {
        if (! config('blizzard.rating_refresh.enabled')) {
            $this->info('Rating refresh lane is disabled (BLIZZARD_RATING_REFRESH_ENABLED).');

            return self::SUCCESS;
        }

        if (Cache::get('blizzard:unhealthy')) {
            $this->warn('Blizzard circuit is open — skipping this run.');

            return self::SUCCESS;
        }

        $season = $ranks->seasonStart();
        if ($season === null) {
            $this->error('No current season with started_at — nothing to refresh.');

            return self::FAILURE;
        }

        $cap = max(1, (int) ($this->option('cap') ?? config('blizzard.rating_refresh.daily_cap', 40000)));
        $backlog = $this->backlog($season['started_at']);
        $total = (clone $backlog)->count();

        // Distinct member identities from the two most recent ladder periods.
        // Ladder names are raw Blizzard casing; characters.name is canonical
        // lowercase, hence LOWER() on the ladder side of the join.
        $periods = LadderRun::query()->select('period_id')->distinct()->orderByDesc('period_id')->limit(2)->pluck('period_id');
        $members = DB::table('ladder_run_members as lm')
            ->join('ladder_runs as r', 'r.id', '=', 'lm.ladder_run_id')
            ->whereIn('r.period_id', $periods->all() ?: [-1])
            ->selectRaw('DISTINCT LOWER(lm.name) AS name, lm.realm_slug, r.region');

        $rows = $backlog
            ->leftJoinSub($members, 'lm', function ($join) {
                $join->on('lm.name', '=', 'characters.name')
                    ->on('lm.realm_slug', '=', 'characters.realm')
                    ->on('lm.region', '=', 'characters.region');
            })
            ->orderByRaw('CASE WHEN lm.name IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('characters.mythic_plus_rating')
            ->orderBy('characters.id')
            ->limit($cap)
            ->get(['characters.region', 'characters.realm', 'characters.name']);

        foreach ($rows as $row) {
            SyncCharacterData::dispatch(
                region: $row->region,
                realm: $row->realm,
                name: $row->name,
                depth: SyncDepth::Shallow,
                origin: SyncOrigin::Proactive,
            );
        }

        $dispatched = $rows->count();
        $remaining = max(0, $total - $dispatched);
        // Two separate writes (not one combined line): Laravel's test double
        // matches each expectsOutputToContain() substring against a distinct
        // doWrite() call, so two substrings landing on the same line only
        // satisfy the first-registered expectation.
        $this->info("Dispatched {$dispatched} rating refresh syncs.");
        $this->line("{$remaining} remaining in backlog.");
        Log::info('ratings:refresh', ['dispatched' => $dispatched, 'remaining' => $remaining, 'cap' => $cap]);

        return self::SUCCESS;
    }

    private function backlog(Carbon $seasonStart): Builder
    {
        return Character::query()
            ->where('characters.game_version', 'retail')
            ->where('characters.level', '>=', (int) config('blizzard.endgame_level', 90))
            ->where('characters.mythic_plus_rating', '>', 0)
            ->where(function (Builder $q) use ($seasonStart) {
                $q->whereNull('characters.rating_synced_at')
                    ->orWhere('characters.rating_synced_at', '<', $seasonStart->format('Y-m-d H:i:s'));
            });
    }
}
