<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterAchievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'achievement_id',
        'completed_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'achievement_id' => 'integer',
            'completed_timestamp' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
