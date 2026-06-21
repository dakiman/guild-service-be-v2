<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BulkUpsertable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterPvpBracket extends Model
{
    use BulkUpsertable;
    use HasFactory;

    /** Columns of character_pvp_brackets_unique — upsert conflict target. */
    public const UNIQUE_KEY = ['character_id', 'bracket'];

    protected $fillable = [
        'character_id',
        'bracket',
        'rating',
        'season_won',
        'season_lost',
        'season_played',
        'weekly_won',
        'weekly_lost',
        'weekly_played',
        'tier_name',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'season_won' => 'integer',
            'season_lost' => 'integer',
            'season_played' => 'integer',
            'weekly_won' => 'integer',
            'weekly_lost' => 'integer',
            'weekly_played' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
