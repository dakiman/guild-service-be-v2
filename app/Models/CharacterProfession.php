<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BulkUpsertable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterProfession extends Model
{
    use BulkUpsertable;
    use HasFactory;

    /** Columns of character_professions_unique — upsert conflict target. */
    public const UNIQUE_KEY = ['character_id', 'profession_id', 'tier_name'];

    protected $fillable = [
        'character_id',
        'profession_id',
        'profession_name',
        'tier_name',
        'expansion_id',
        'skill_points',
        'max_skill_points',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'profession_id' => 'integer',
            'expansion_id' => 'integer',
            'skill_points' => 'integer',
            'max_skill_points' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(GameDataExpansion::class, 'expansion_id');
    }
}
