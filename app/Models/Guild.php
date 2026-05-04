<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'realm',
        'region',
        'display_name',
        'display_realm',
        'faction',
        'achievement_points',
        'member_count',
        'created_timestamp',
        'num_of_searches',
        'last_searched_at',
        'roster_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'roster_synced_at' => 'datetime',
            'last_searched_at' => 'datetime',
            'achievement_points' => 'integer',
            'member_count' => 'integer',
            'num_of_searches' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    /**
     * Idempotent backfill: link any GuildMember in this guild whose
     * character_id is still NULL but a matching retail Character now exists.
     * Used by both SyncGuildData (post-roster-upsert) and GuildController
     * (pre-render) so the eager-loaded `character` relation is populated even
     * when SyncCharacterData::linkGuildMembers hasn't fired yet for that row.
     * Subquery form rather than UPDATE...FROM so it runs on Postgres + SQLite.
     */
    public function backfillMemberCharacterIds(): void
    {
        GuildMember::query()
            ->where('guild_id', $this->id)
            ->whereNull('character_id')
            ->update([
                'character_id' => Character::query()
                    ->select('id')
                    ->whereColumn('characters.name', 'guild_members.name')
                    ->whereColumn('characters.realm', 'guild_members.realm')
                    ->where('region', $this->region)
                    ->where('game_version', 'retail')
                    ->limit(1),
            ]);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function scopeByIdentity(Builder $query, string $name, string $realm, string $region): Builder
    {
        return $query->where('name', $name)
            ->where('realm', $realm)
            ->where('region', $region);
    }

    public function scopeMostPopular(Builder $query, int $limit = 5): Builder
    {
        return $query->orderByDesc('num_of_searches')->limit($limit);
    }

    public function scopeRecentlySearched(Builder $query, int $limit = 5): Builder
    {
        return $query->whereNotNull('last_searched_at')
            ->orderByDesc('last_searched_at')
            ->limit($limit);
    }

    public function scopeTopByAchievementPoints(Builder $query, int $limit = 5): Builder
    {
        return $query->orderByDesc('achievement_points')->limit($limit);
    }

    public function scopeLargestByMembers(Builder $query, int $limit = 5): Builder
    {
        return $query->orderByDesc('member_count')->limit($limit);
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
            ->where(function ($q) use ($prefix, $substring) {
                $q->where('name', 'LIKE', $prefix)
                    ->orWhere('name', 'LIKE', $substring);
            })
            ->orderByRaw('CASE WHEN name LIKE ? THEN 1 ELSE 2 END', [$prefix])
            ->orderByRaw('num_of_searches DESC NULLS LAST')
            ->orderBy('name')
            ->limit($limit);
    }

    public function scopeRecentlyCreated(Builder $query, int $limit = 5): Builder
    {
        return $query->where('created_timestamp', '>', 0)
            ->orderByDesc('created_timestamp')
            ->limit($limit);
    }

    public function isStale(): bool
    {
        if (! $this->updated_at) {
            return true;
        }

        return $this->updated_at->diffInSeconds(now()) > config('blizzard.staleness.guild.basic');
    }

    public function isRosterStale(): bool
    {
        if (! $this->roster_synced_at) {
            return true;
        }

        return $this->roster_synced_at->diffInSeconds(now()) > config('blizzard.staleness.guild.roster');
    }
}
