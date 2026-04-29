<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterReputation extends Model
{
    use HasFactory;

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
}
