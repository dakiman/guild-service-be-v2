<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\GameDataTitle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterTitle extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'title_id',
        'name',
        'display_string',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'title_id' => 'integer',
            'is_selected' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function gameData(): BelongsTo
    {
        return $this->belongsTo(GameDataTitle::class, 'title_id');
    }
}
