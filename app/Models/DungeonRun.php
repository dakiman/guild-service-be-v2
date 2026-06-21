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
        'keystone_run_id',
        'season',
        'dungeon_id',
        'dungeon_name',
        'keystone_level',
        'duration',
        'completed_timestamp',
        'is_completed_on_time',
        'affixes',
        'raiderio_score',
        'raiderio_url',
    ];

    protected function casts(): array
    {
        return [
            'affixes' => 'array',
            'is_completed_on_time' => 'boolean',
            'season' => 'integer',
            'keystone_level' => 'integer',
            'keystone_run_id' => 'integer',
            'raiderio_score' => 'decimal:1',
        ];
    }

    /**
     * The columns of `uq_dungeon_run` — the single source of truth for run
     * identity. updateOrCreate previously matched only the first three, which
     * disagreed with the DB unique index.
     */
    public const UNIQUE_KEY = ['season', 'dungeon_id', 'completed_timestamp', 'duration'];

    /**
     * Atomically upsert a run on its full unique key and return the row.
     *
     * upsert() (INSERT ... ON CONFLICT DO UPDATE) over updateOrCreate's
     * check-then-insert: concurrent teammate syncs both SELECT-miss then INSERT,
     * raising SQLSTATE[23505] which aborts the enclosing mythic+ transaction /
     * crawl loop. ON CONFLICT is atomic and never throws on the duplicate. (P1.2)
     *
     * @param  array<string, mixed>  $attributes  full row incl. the key columns
     */
    public static function upsertRun(array $attributes): self
    {
        // Route through a model instance so cast columns (affixes JSON) serialize
        // — Eloquent's upsert() does not apply casts to the values it's handed.
        $row = (new self($attributes))->getAttributes();
        $update = array_values(array_diff(array_keys($row), self::UNIQUE_KEY));

        self::upsert([$row], self::UNIQUE_KEY, $update);

        return self::query()
            ->where('season', $attributes['season'])
            ->where('dungeon_id', $attributes['dungeon_id'])
            ->where('completed_timestamp', $attributes['completed_timestamp'])
            ->where('duration', $attributes['duration'])
            ->firstOrFail();
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
