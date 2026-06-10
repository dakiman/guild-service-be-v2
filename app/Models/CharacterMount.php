<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterMount extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'mount_id',
        'name',
        'is_useable',
    ];

    protected function casts(): array
    {
        return [
            'mount_id' => 'integer',
            'is_useable' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function gameData(): BelongsTo
    {
        return $this->belongsTo(GameDataMount::class, 'mount_id');
    }
}
