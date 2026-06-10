<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'guild_id',
        'character_id',
        'name',
        'realm',
        'display_name',
        'display_realm',
        'level',
        'class_id',
        'race_id',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'class_id' => 'integer',
            'race_id' => 'integer',
            'rank' => 'integer',
        ];
    }

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
