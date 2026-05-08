<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Jobs\SyncGuildData;
use App\Exceptions\EntityNotFoundException;
use App\Http\Resources\GuildSummaryResource;
use App\Models\Guild;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class GuildService
{
    public function getByIdentity(string $region, string $realm, string $name): ?Guild
    {
        $guild = Guild::byIdentity($name, $realm, $region)->first();

        if (! $guild) {
            if (Cache::has("blizzard:not-found:guild:{$region}:{$realm}:{$name}")) {
                throw new EntityNotFoundException;
            }

            return null;
        }

        $guild->update([
            'num_of_searches' => \Illuminate\Support\Facades\DB::raw('num_of_searches + 1'),
            'last_searched_at' => now(),
        ]);

        if ($guild->isStale() || $guild->isRosterStale()) {
            SyncGuildData::dispatch($region, $realm, $name, forceRosterFanout: false, forceCascade: true);
        }

        return $guild;
    }

    /**
     * @return array{recently_searched: Collection, most_popular: Collection}
     */
    public function getPopular(): array
    {
        return Cache::remember('guilds:popular', 30, fn () => [
            'recently_searched' => Guild::recentlySearched(5)->get(),
            'most_popular' => Guild::mostPopular(5)->get(),
        ]);
    }

    public function getDiscover(): array
    {
        return Cache::remember('guilds:discover', 60, function () {
            $top = Guild::topByAchievementPoints()->get()
                ->each(function ($g) {
                    $g->metric = $g->achievement_points;
                    $g->metric_label = 'achievement_points';
                });

            $largest = Guild::largestByMembers()->get()
                ->each(function ($g) {
                    $g->metric = $g->member_count;
                    $g->metric_label = 'member_count';
                });

            $created = Guild::recentlyCreated()->get()
                ->each(function ($g) {
                    $g->metric = $g->created_timestamp;
                    $g->metric_label = 'created_timestamp';
                });

            $factionSplit = Guild::query()
                ->selectRaw('faction, COUNT(*) as count')
                ->groupBy('faction')
                ->pluck('count', 'faction');

            $regionBreakdown = Guild::query()
                ->selectRaw('region, faction, COUNT(*) as count')
                ->groupBy('region', 'faction')
                ->get()
                ->groupBy('region')
                ->map(fn ($rows, $region) => [
                    'region' => $region,
                    'alliance' => (int) ($rows->firstWhere('faction', 'Alliance')?->count ?? 0),
                    'horde' => (int) ($rows->firstWhere('faction', 'Horde')?->count ?? 0),
                ])
                ->values()
                ->all();

            return [
                'recently_searched' => $this->summarize(Guild::recentlySearched(5)->get()),
                'most_popular' => $this->summarize(Guild::mostPopular(5)->get()),
                'top_achievement_points' => $this->summarize($top),
                'largest_by_members' => $this->summarize($largest),
                'recently_created' => $this->summarize($created),
                'faction_split' => [
                    'Alliance' => (int) ($factionSplit['Alliance'] ?? 0),
                    'Horde' => (int) ($factionSplit['Horde'] ?? 0),
                ],
                'region_breakdown' => $regionBreakdown,
            ];
        });
    }

    private function summarize(Collection $guilds): array
    {
        $request = request();

        return $guilds->map(fn (Guild $g) => (new GuildSummaryResource($g))->toArray($request))->all();
    }
}
