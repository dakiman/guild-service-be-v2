<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BulkUpsertable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterReputation extends Model
{
    use BulkUpsertable;
    use HasFactory;

    /** Columns of character_reputations_unique — upsert conflict target. */
    public const UNIQUE_KEY = ['character_id', 'faction_id'];

    protected $fillable = [
        'character_id',
        'faction_id',
        'faction_name',
        'standing',
        'value',
        'max',
    ];

    protected function casts(): array
    {
        return [
            'faction_id' => 'integer',
            'value' => 'integer',
            'max' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function faction(): BelongsTo
    {
        return $this->belongsTo(GameDataFaction::class, 'faction_id');
    }
}
