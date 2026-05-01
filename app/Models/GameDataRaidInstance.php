<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDataRaidInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'expansion_id',
        'display_order',
        'media_url',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'expansion_id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(GameDataExpansion::class, 'expansion_id');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(GameDataRaidEncounter::class, 'raid_instance_id')
            ->orderBy('display_order');
    }
}
