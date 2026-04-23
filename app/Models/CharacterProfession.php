<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterProfession extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'profession_id',
        'profession_name',
        'tier_name',
        'skill_points',
        'max_skill_points',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'profession_id' => 'integer',
            'skill_points' => 'integer',
            'max_skill_points' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
