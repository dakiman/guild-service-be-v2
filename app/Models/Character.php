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
        'reputations_synced_at',
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
            'mythic_plus_rating_by_spec' => 'array',
            'recruitment' => 'boolean',
            'mythics_synced_at' => 'datetime',
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

    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(GameDataTitle::class, 'character_titles', 'character_id', 'title_id');
    }

    public function activeTitle(): BelongsTo
    {
        return $this->belongsTo(GameDataTitle::class, 'active_title_id');
    }

    public function reputations(): HasMany
    {
        return $this->hasMany(CharacterReputation::class);
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
    ];

    public function scopeMostPopular(Builder $query, int $limit = 5): Builder
    {
        return $query->select(self::SUMMARY_COLUMNS)
            ->orderByDesc('num_of_searches')
            ->limit($limit);
    }

    public function scopeRecentlySearched(Builder $query, int $limit = 5): Builder
    {
        return $query->select(self::SUMMARY_COLUMNS)
            ->whereNotNull('last_searched_at')
            ->orderByDesc('last_searched_at')
            ->limit($limit);
    }

    public function scopeNameSearch(Builder $query, string $q, int $limit = 8): Builder
    {
        $needle = strtolower(trim($q));

        if (strlen($needle) < 2) {
            return $query->whereRaw('1 = 0');
        }

        // Names are stored canonical-lowercase (see BlizzardIdentity::name); plain LIKE is case-correct on Postgres.
        $prefix = $needle.'%';
        $substring = '%'.$needle.'%';

        return $query
            // Only the columns CharacterSuggestionResource emits (+ ordering keys);
            // never haul media/talents/equipment/stats JSONB on a per-keystroke query. (P2.3)
            ->select(['id', 'region', 'realm', 'display_realm', 'name', 'display_name', 'class_id', 'level', 'faction', 'num_of_searches'])
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
        return $query->where('level', config('blizzard.endgame_level', 90))
            ->where(function (Builder $q) {
                $q->whereHas('raidEncounterKills')
                    ->orWhereHas('dungeonRuns');
            });
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
}
