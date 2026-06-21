<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BulkUpsertable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaidEncounterKill extends Model
{
    use BulkUpsertable;
    use HasFactory;

    /** Columns of raid_encounter_kills_unique — upsert conflict target. */
    public const UNIQUE_KEY = ['character_id', 'encounter_id', 'difficulty'];

    protected $fillable = [
        'character_id',
        'expansion_name',
        'instance_id',
        'instance_name',
        'encounter_id',
        'encounter_name',
        'difficulty',
        'completed_count',
        'last_kill_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'instance_id' => 'integer',
            'encounter_id' => 'integer',
            'completed_count' => 'integer',
            'last_kill_timestamp' => 'integer',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
