<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LadderRun extends Model
{
    protected $fillable = [
        'period_id', 'region', 'dungeon_id', 'keystone_level', 'duration',
        'completed_timestamp', 'is_completed_on_time',
        'comp_signature', 'run_hash',
    ];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'dungeon_id' => 'integer',
            'keystone_level' => 'integer',
            'duration' => 'integer',
            'completed_timestamp' => 'integer',
            'is_completed_on_time' => 'boolean',
        ];
    }

    public function memberEntries(): HasMany
    {
        return $this->hasMany(LadderRunMember::class);
    }
}
