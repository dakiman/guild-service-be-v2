<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterToy extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'toy_id',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'toy_id' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
