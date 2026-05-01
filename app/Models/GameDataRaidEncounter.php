<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataRaidEncounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'raid_instance_id',
        'name',
        'display_order',
        'creature_display_id',
        'portrait_url',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'raid_instance_id' => 'integer',
            'display_order' => 'integer',
            'creature_display_id' => 'integer',
        ];
    }

    public function raidInstance(): BelongsTo
    {
        return $this->belongsTo(GameDataRaidInstance::class, 'raid_instance_id');
    }
}
