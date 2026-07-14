<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GameDataSeason extends Model
{
    protected $fillable = [
        'id',
        'slug',
        'name',
        'raiderio_tier_slug',
        'raiderio_expansion_id',
        'expansion_id',
        'is_current',
        'started_at',
        'ended_at',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'raiderio_expansion_id' => 'integer',
            'expansion_id' => 'integer',
            'is_current' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(GameDataExpansion::class, 'expansion_id');
    }

    public function archive(): HasOne
    {
        return $this->hasOne(SeasonArchive::class, 'season_id');
    }
}
