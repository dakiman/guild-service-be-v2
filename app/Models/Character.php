<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncDepth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'gender',
        'faction',
        'race_id',
        'class_id',
        'level',
        'achievement_points',
        'average_item_level',
        'equipped_item_level',
        'active_specialization',
        'media',
        'talents',
        'equipment',
        'recruitment',
        'num_of_searches',
        'last_searched_at',
        'mythics_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'talents' => 'array',
            'equipment' => 'array',
            'recruitment' => 'boolean',
            'mythics_synced_at' => 'datetime',
            'last_searched_at' => 'datetime',
            'race_id' => 'integer',
            'class_id' => 'integer',
            'level' => 'integer',
            'num_of_searches' => 'integer',
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

    public function guildMembership(): HasOne
    {
        return $this->hasOne(GuildMember::class);
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

    public function scopeRecruiting(Builder $query): Builder
    {
        return $query->where('recruitment', true);
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
}
