<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonArchive extends Model
{
    protected $primaryKey = 'season_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['season_id', 'payload', 'snapshotted_at'];

    protected function casts(): array
    {
        return [
            'season_id' => 'integer',
            'payload' => 'array',
            'snapshotted_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(GameDataSeason::class, 'season_id');
    }
}
