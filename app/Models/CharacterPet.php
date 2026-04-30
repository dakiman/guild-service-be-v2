<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterPet extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'pet_id',
        'species_id',
        'name',
        'level',
        'breed_id',
        'quality',
        'is_favorite',
        'creature_display_id',
    ];

    protected function casts(): array
    {
        return [
            'pet_id' => 'integer',
            'species_id' => 'integer',
            'level' => 'integer',
            'breed_id' => 'integer',
            'is_favorite' => 'boolean',
            'creature_display_id' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
