<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'guild_id',
        'name',
        'realm',
        'region',
        'display_name',
        'display_realm',
        'game_version',
        'gender',
        'faction',
        'race_id',
        'class_id',
        'level',
        'achievement_points',
        'average_item_level',
        'equipped_item_level',
        'mythic_plus_rating',
        'mythic_plus_rating_by_spec',
        'mythic_plus_rating_color',
        'rating_synced_at',
        'active_specialization',
        'active_specialization_id',
        'talent_tree_id',
        'talent_loadout_code',
        'media',
        'talents',
        'equipment',
        'stats',
        'recruitment',
        'num_of_searches',
        'last_searched_at',
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
        'stats_synced_at',
        'titles_synced_at',
        'active_title_id',
        'title_ids',
        'reputations_synced_at',
        'reputations',
        'collections_synced_at',
        'achievements_synced_at',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'talents' => 'array',
            'equipment' => 'array',
            'stats' => 'array',
            'title_ids' => 'array',
            'reputations' => 'array',
            'mythic_plus_rating_by_spec' => 'array',
            'recruitment' => 'boolean',
            'mythics_synced_at' => 'datetime',
            'rating_synced_at' => 'datetime',
            'pvp_synced_at' => 'datetime',
            'professions_synced_at' => 'datetime',
            'raids_synced_at' => 'datetime',
            'stats_synced_at' => 'datetime',
            'titles_synced_at' => 'datetime',
            'reputations_synced_at' => 'datetime',
            'collections_synced_at' => 'datetime',
            'achievements_synced_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_searched_at' => 'datetime',
            'race_id' => 'integer',
            'class_id' => 'integer',
            'level' => 'integer',
            'mythic_plus_rating' => 'integer',
            'num_of_searches' => 'integer',
            'active_specialization_id' => 'integer',
            'talent_tree_id' => 'integer',
            'active_title_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function dungeonRuns(): BelongsToMany
    {
        return $this->belongsToMany(DungeonRun::class, 'dungeon_run_members')
            ->withPivot(['spec_id', 'spec_name', 'equipped_item_level'])
            ->withTimestamps();
    }

    /**
     * Identity rows (name/realm/region) of everyone — this character included —
     * who appeared in this character's Mythic+ runs for the given season.
     * Feeds the teammate crawl in SyncCharacterData.
     *
     * Deliberately three cheap index lookups instead of one join: drive from
     * this character's own pivot rows (character_id index), filter the run
     * ids by pkey, then read members by run id. The former single-statement
     * join let Postgres start from `dungeon_runs.season = ?`; right after a
     * rollover the new season isn't in pg_stats yet (autoanalyze needs ~10%
     * of a 6M-row table to change), the planner estimated 1 row, and it ran
     * that season scan twice in a nested loop — 48k × 48k probes, hours per
     * job, every blizzard-user-sync worker wedged and unkillable
     * (2026-08-23..28). This shape has no season-cardinality dependency.
     *
     * @return Collection<int, object{character_name: string, character_realm: string, character_region: string}>
     */
    public function seasonTeammateRows(int $season): Collection
    {
        $runIds = DB::table('dungeon_run_members')
            ->where('character_id', $this->id)
            ->pluck('dungeon_run_id');

        if ($runIds->isEmpty()) {
            return collect();
        }

        $seasonRunIds = DB::table('dungeon_runs')
            ->whereIn('id', $runIds)
            ->where('season', $season)
            ->pluck('id');

        if ($seasonRunIds->isEmpty()) {
            return collect();
        }

        return DB::table('dungeon_run_members')
            ->whereIn('dungeon_run_id', $seasonRunIds)
            ->get(['character_name', 'character_realm', 'character_region']);
    }

    public function pvpBrackets(): HasMany
    {
        return $this->hasMany(CharacterPvpBracket::class);
    }

    public function professions(): HasMany
    {
        return $this->hasMany(CharacterProfession::class);
    }

    public function raidEncounterKills(): HasMany
    {
        return $this->hasMany(RaidEncounterKill::class);
    }

    public function activeTitle(): BelongsTo
    {
        return $this->belongsTo(GameDataTitle::class, 'active_title_id');
    }

    /** @return list<array{id: int, name_male: ?string, name_female: ?string}> */
    public function resolvedTitles(): array
    {
        $ids = $this->title_ids ?? [];
        if ($ids === []) {
            return [];
        }

        return GameDataTitle::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'name_male', 'name_female'])
            ->map(fn (GameDataTitle $t) => [
                'id' => (int) $t->id,
                'name_male' => $t->name_male,
                'name_female' => $t->name_female,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function resolvedReputations(): array
    {
        $reps = $this->reputations ?? [];
        if ($reps === []) {
            return [];
        }

        $factions = GameDataFaction::query()
            ->with('expansion')
            ->whereIn('id', array_column($reps, 'faction_id'))
            ->get()
            ->keyBy('id');

        return array_map(function (array $rep) use ($factions) {
            $out = [
                'faction_id' => (int) $rep['faction_id'],
                'faction_name' => (string) $rep['faction_name'],
                'standing' => (string) $rep['standing'],
                'value' => (int) $rep['value'],
                'max' => (int) $rep['max'],
            ];

            $faction = $factions->get((int) $rep['faction_id']);
            if ($faction !== null) {
                // faction key ABSENT (not null) when there's no game-data row — FE contract.
                $out['faction'] = [
                    'id' => $faction->id,
                    'name' => $faction->name,
                    'parent_faction_id' => $faction->parent_faction_id,
                    'expansion' => $faction->expansion !== null
                        ? [
                            'id' => $faction->expansion->id,
                            'name' => $faction->expansion->name,
                            'display_order' => $faction->expansion->display_order,
                        ]
                        : null,
                ];
            }

            return $out;
        }, $reps);
    }

    public function mounts(): HasMany
    {
        return $this->hasMany(CharacterMount::class);
    }

    public function pets(): HasMany
    {
        return $this->hasMany(CharacterPet::class);
    }

    public function toys(): HasMany
    {
        return $this->hasMany(CharacterToy::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(CharacterAchievement::class);
    }

    public function guildMembership(): HasOne
    {
        return $this->hasOne(GuildMember::class);
    }

    public function rank(): HasOne
    {
        return $this->hasOne(CharacterRank::class);
    }

    public function scopeByIdentity(Builder $query, string $name, string $realm, string $region): Builder
    {
        return $query->where('name', $name)
            ->where('realm', $realm)
            ->where('region', $region)
            ->where('game_version', 'retail');
    }

    /**
     * Columns CharacterSummaryResource needs — scalar fields + the `media` JSONB
     * (avatar) only. Excludes the heavy talents/equipment/stats/rating-by-spec
     * blobs that the summary never reads. (P2.3)
     *
     * @var array<int, string>
     */
    private const SUMMARY_COLUMNS = [
        'id', 'name', 'realm', 'region', 'display_name', 'display_realm',
        'class_id', 'level', 'faction', 'active_specialization', 'media',
        'num_of_searches', 'last_searched_at',
        'mythic_plus_rating', 'mythic_plus_rating_color',
    ];

    public function scopeMostPopular(Builder $query, int $limit = 5): Builder
    {
        return $query->select(self::SUMMARY_COLUMNS)
            ->with('rank:character_id,region_rank')
            ->orderByDesc('num_of_searches')
            ->limit($limit);
    }

    public function scopeRecentlySearched(Builder $query, int $limit = 5): Builder
    {
        return $query->select(self::SUMMARY_COLUMNS)
            ->with('rank:character_id,region_rank')
            ->whereNotNull('last_searched_at')
            ->orderByDesc('last_searched_at')
            ->limit($limit);
    }

    public function scopeNameSearch(Builder $query, string $q, int $limit = 8): Builder
    {
        $needle = Str::lower(trim($q));

        if (mb_strlen($needle) < 2) {
            return $query->whereRaw('1 = 0');
        }

        // Names are stored canonical-lowercase (see BlizzardIdentity::name); plain LIKE is case-correct on Postgres.
        $prefix = $needle.'%';
        $substring = '%'.$needle.'%';

        return $query
            // Only the columns CharacterSuggestionResource emits (+ ordering keys);
            // never haul media/talents/equipment/stats JSONB on a per-keystroke query. (P2.3)
            ->select(['id', 'region', 'realm', 'display_realm', 'name', 'display_name', 'class_id', 'level', 'faction', 'num_of_searches', 'mythic_plus_rating', 'mythic_plus_rating_color'])
            ->with('rank:character_id,region_rank')
            ->where('game_version', 'retail')
            ->where(function ($q) use ($prefix, $substring) {
                $q->where('name', 'LIKE', $prefix)
                    ->orWhere('name', 'LIKE', $substring);
            })
            ->orderByRaw('CASE WHEN name LIKE ? THEN 1 ELSE 2 END', [$prefix])
            ->orderByRaw('num_of_searches DESC NULLS LAST')
            ->orderBy('name')
            ->limit($limit);
    }

    public function scopeRecruiting(Builder $query): Builder
    {
        return $query->where('recruitment', true);
    }

    public function scopeEndgameActive(Builder $query): Builder
    {
        return $query->where('level', '>=', config('blizzard.endgame_level', 90))
            ->where(function (Builder $q) {
                $q->whereHas('raidEncounterKills')
                    ->orWhereHas('dungeonRuns');
            });
    }

    /**
     * Endgame characters get the Full slice treatment; everything below only
     * ever receives Shallow/Standard syncs and profile-only freshness. A null
     * level (shell row) casts to 0 — not endgame until a sync writes the level.
     */
    public function isEndgame(): bool
    {
        return (int) $this->level >= (int) config('blizzard.endgame_level', 90);
    }

    public function isStale(): bool
    {
        if (! $this->updated_at) {
            return true;
        }

        return $this->updated_at->diffInSeconds(now()) > config('blizzard.staleness.character.profile');
    }

    public function isMythicsStale(): bool
    {
        if (! $this->mythics_synced_at) {
            return true;
        }

        return $this->mythics_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.mythic_plus');
    }

    public function isPvpStale(): bool
    {
        return ! $this->pvp_synced_at
            || $this->pvp_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.pvp');
    }

    public function isProfessionsStale(): bool
    {
        return ! $this->professions_synced_at
            || $this->professions_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.professions');
    }

    public function isRaidsStale(): bool
    {
        return ! $this->raids_synced_at
            || $this->raids_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.raids');
    }

    public function isStatsStale(): bool
    {
        return ! $this->stats_synced_at
            || $this->stats_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.stats');
    }

    public function isTitlesStale(): bool
    {
        return ! $this->titles_synced_at
            || $this->titles_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.titles');
    }

    public function isReputationsStale(): bool
    {
        return ! $this->reputations_synced_at
            || $this->reputations_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.reputations');
    }

    public function isCollectionsStale(): bool
    {
        return ! $this->collections_synced_at
            || $this->collections_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.collections');
    }

    public function isAchievementsStale(): bool
    {
        return ! $this->achievements_synced_at
            || $this->achievements_synced_at->diffInSeconds(now()) > config('blizzard.staleness.character.achievements');
    }

    /**
     * Per-slice freshness map ('fresh' | 'stale' | 'never_synced'). Single
     * source of truth shared by CharacterResource (meta.freshness in the body)
     * and CharacterController (the X-Sync-Status header) — see isNeverSynced().
     *
     * @return array<string, string>
     */
    public function freshness(): array
    {
        // Sub-endgame characters never sync slices, so slice keys are omitted
        // wholesale — otherwise their null timestamps would read never_synced
        // forever and isNeverSynced() would keep the API in 'syncing' state.
        if (! $this->isEndgame()) {
            return ['profile' => $this->freshnessFor('updated_at', 'profile')];
        }

        $freshness = [
            'profile' => $this->freshnessFor('updated_at', 'profile'),
            'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
            'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
            'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
            'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
            'stats' => $this->freshnessFor('stats_synced_at', 'stats'),
            'titles' => $this->freshnessFor('titles_synced_at', 'titles'),
            'reputations' => $this->freshnessFor('reputations_synced_at', 'reputations'),
            'collections' => $this->freshnessFor('collections_synced_at', 'collections'),
        ];

        // Drop achievements freshness key when flag is off — FE has nothing to filter on.
        if (config('blizzard.sync.achievements_enabled')) {
            $freshness['achievements'] = $this->freshnessFor('achievements_synced_at', 'achievements');
        }

        return $freshness;
    }

    public function isNeverSynced(): bool
    {
        return in_array('never_synced', $this->freshness(), true);
    }

    private function freshnessFor(string $timestampField, string $configKey): string
    {
        $ts = $this->{$timestampField} ?? null;
        if ($ts === null) {
            return 'never_synced';
        }

        $threshold = (int) config("blizzard.staleness.character.{$configKey}", 900);

        return $ts->diffInSeconds(now()) > $threshold ? 'stale' : 'fresh';
    }
}
