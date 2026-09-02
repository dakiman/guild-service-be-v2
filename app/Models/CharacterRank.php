<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterRank extends Model
{
    protected $primaryKey = 'character_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'character_id', 'season_id', 'region', 'connected_realm_id', 'class_id', 'spec_id', 'rating',
        'world_rank', 'region_rank', 'realm_rank', 'class_rank', 'spec_rank',
        'world_pop', 'region_pop', 'realm_pop', 'class_pop', 'spec_pop', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'season_id' => 'integer', 'connected_realm_id' => 'integer', 'class_id' => 'integer',
            'spec_id' => 'integer', 'rating' => 'integer',
            'world_rank' => 'integer', 'region_rank' => 'integer', 'realm_rank' => 'integer',
            'class_rank' => 'integer', 'spec_rank' => 'integer',
            'world_pop' => 'integer', 'region_pop' => 'integer', 'realm_pop' => 'integer',
            'class_pop' => 'integer', 'spec_pop' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
