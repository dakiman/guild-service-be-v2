<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DungeonRunMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'dungeon_run_id',
        'character_id',
        'character_name',
        'character_realm',
        'character_region',
        'display_realm',
        'spec_id',
        'spec_name',
        'equipped_item_level',
    ];

    protected function casts(): array
    {
        return [
            'spec_id' => 'integer',
            'equipped_item_level' => 'integer',
        ];
    }

    public function dungeonRun(): BelongsTo
    {
        return $this->belongsTo(DungeonRun::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
