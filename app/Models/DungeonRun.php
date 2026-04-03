<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DungeonRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'season',
        'dungeon_id',
        'dungeon_name',
        'keystone_level',
        'duration',
        'completed_timestamp',
        'is_completed_on_time',
        'affixes',
    ];

    protected function casts(): array
    {
        return [
            'affixes' => 'array',
            'is_completed_on_time' => 'boolean',
            'season' => 'integer',
            'keystone_level' => 'integer',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'dungeon_run_members')
            ->withPivot([
                'character_name',
                'character_realm',
                'character_region',
                'spec_id',
                'spec_name',
                'equipped_item_level',
            ])
            ->withTimestamps();
    }

    public function memberEntries(): HasMany
    {
        return $this->hasMany(DungeonRunMember::class);
    }

    public function scopeBySeason(Builder $query, int $season): Builder
    {
        return $query->where('season', $season);
    }
}
