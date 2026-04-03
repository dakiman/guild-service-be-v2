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
