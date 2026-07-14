<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DungeonRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared M+ leaderboard queries: the live TopRuns/TopKeys endpoints and the
 * season-archive snapshot (SeasonArchiveService) must produce identical row
 * shapes, so both run through here.
 *
 * $season === null means "no season filter" — the fail-open path for an
 * empty game_data_seasons registry, preserving pre-registry behavior.
 */
class MythicPlusLeaderboards
{
    /**
     * Leaderboard depth. Pagination is clamped here so the endpoints never
     * expose the full dungeon_runs table (~2.6M rows), and the count query
     * never scans past the cap.
     */
    public const LEADERBOARD_CAP = 100;

    private function baseQuery(?int $season, ?int $dungeonId = null): Builder
    {
        $query = DungeonRun::query()->where('is_completed_on_time', true);

        if ($season !== null) {
            $query->where('season', $season);
        }

        if ($dungeonId !== null) {
            $query->where('dungeon_id', $dungeonId);
        }

        return $query;
    }

    /**
     * Count inside the cap only — a plain count() scans every matching row
     * just to report a total we'd clamp anyway.
     */
    public function cappedTotal(?int $season, ?int $dungeonId = null): int
    {
        return DB::query()
            ->fromSub($this->baseQuery($season, $dungeonId)->select('id')->limit(self::LEADERBOARD_CAP), 'capped')
            ->count();
    }

    /** @return array<int, array<string, mixed>> */
    public function topRuns(?int $season, int $offset, int $limit, ?int $dungeonId = null): array
    {
        return $this->baseQuery($season, $dungeonId)
            ->orderByDesc('keystone_level')
            ->orderBy('duration')
            // `id` tiebreaker keeps page boundaries deterministic across
            // separately-cached page queries when level+duration tie.
            ->orderBy('id')
            ->with('memberEntries.character:id,class_id')
            ->skip($offset)
            ->take($limit)
            ->get()
            ->map(fn (DungeonRun $run) => $this->mapRun($run))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function mapRun(DungeonRun $run): array
    {
        return [
            'id' => $run->id,
            'dungeon_id' => $run->dungeon_id,
            'dungeon_name' => $run->dungeon_name,
            'keystone_level' => $run->keystone_level,
            'duration' => $run->duration,
            'is_completed_on_time' => $run->is_completed_on_time,
            'affixes' => $run->affixes,
            'completed_at' => $run->completed_timestamp,
            'members' => $run->memberEntries->map(fn ($m) => [
                'name' => $m->character_name,
                'realm' => $m->character_realm,
                'region' => $m->character_region,
                'spec_id' => $m->spec_id,
                'spec_name' => $m->spec_name,
                'class_id' => $m->character?->class_id,
                'ilvl' => $m->equipped_item_level,
            ])->all(),
        ];
    }

    /**
     * One row per dungeon via a window function (portable DISTINCT ON)
     * instead of loading every timed run into memory and grouping in PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topKeys(?int $season): array
    {
        $ranked = DungeonRun::query()
            ->where('is_completed_on_time', true)
            // `id` tiebreaker keeps the chosen row deterministic across
            // re-queries (and thus the cache) when level+duration tie.
            ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY dungeon_id ORDER BY keystone_level DESC, duration ASC, id ASC) as rn');

        if ($season !== null) {
            $ranked->where('season', $season);
        }

        $topRuns = DungeonRun::query()
            ->fromSub($ranked, 'dungeon_runs')
            ->where('rn', 1)
            ->with('memberEntries.character:id,name,realm,region,class_id')
            ->orderByDesc('keystone_level')
            ->orderBy('duration')
            ->get();

        return $topRuns->map(function (DungeonRun $run) {
            $member = $run->memberEntries->firstWhere('character_id', '!=', null);
            $character = $member?->character;

            return [
                'dungeon_id' => $run->dungeon_id,
                'dungeon_name' => $run->dungeon_name,
                'key_level' => $run->keystone_level,
                'duration' => $run->duration,
                'character' => $character ? [
                    'name' => $character->name,
                    'realm' => $character->realm,
                    'region' => $character->region,
                    'class_id' => $character->class_id,
                ] : null,
            ];
        })->values()->all();
    }
}
