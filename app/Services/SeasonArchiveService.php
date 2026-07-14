<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DungeonRun;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataSeason;
use App\Models\SeasonArchive;
use Illuminate\Support\Facades\Cache;

class SeasonArchiveService
{
    public function __construct(
        private readonly MythicPlusLeaderboards $leaderboards,
    ) {}

    /**
     * Freeze the given season's M+ page into one immutable payload.
     *
     * Leaderboards are recomputed season-scoped (identical code path to the
     * live endpoints via MythicPlusLeaderboards). top_performers and
     * class_distribution are copied from the warmed stats:characters cache —
     * "what the page showed on the season's last day" — and degrade to empty
     * arrays when the cache is cold. The dungeons list captures the CURRENT
     * pool, so snapshot() must run BEFORE the new season's pve sync
     * (season:rollover orders it that way).
     */
    public function snapshot(GameDataSeason $season, bool $force = false): SeasonArchive
    {
        if (! $force && SeasonArchive::whereKey($season->id)->exists()) {
            throw new \RuntimeException(
                "Archive for {$season->slug} already exists — re-run with --force to overwrite."
            );
        }

        $stats = Cache::get(CharacterStatsService::CACHE_KEY) ?? [];
        $now = now();

        $payload = [
            'meta' => [
                'season_id' => (int) $season->id,
                'slug' => (string) $season->slug,
                'name' => (string) $season->name,
                'snapshotted_at' => $now->toIso8601String(),
                'total_runs' => DungeonRun::where('season', $season->id)->count(),
            ],
            'top_runs' => $this->leaderboards->topRuns($season->id, 0, MythicPlusLeaderboards::LEADERBOARD_CAP),
            'top_keys' => ['dungeons' => $this->leaderboards->topKeys($season->id)],
            'top_performers' => [
                'mythic_plus' => $stats['top_performers']['mythic_plus'] ?? [],
            ],
            'class_distribution' => $stats['class_distribution'] ?? [],
            'dungeons' => GameDataMythicKeystoneDungeon::query()
                ->orderBy('name')
                ->get()
                ->map(fn (GameDataMythicKeystoneDungeon $d) => [
                    'id' => (int) $d->id,
                    'name' => (string) $d->name,
                    'media_url' => $d->media_url,
                    'keystone_upgrades' => $d->keystone_upgrades,
                ])
                ->values()
                ->all(),
        ];

        return SeasonArchive::updateOrCreate(
            ['season_id' => $season->id],
            ['payload' => $payload, 'snapshotted_at' => $now],
        );
    }
}
