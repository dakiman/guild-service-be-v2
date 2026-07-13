<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataMythicKeystoneDungeon extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'media_url',
        'keystone_upgrades',
        'journal_instance_id',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'keystone_upgrades' => 'array',
            'journal_instance_id' => 'integer',
        ];
    }

    /**
     * Soft join key — not a FK constraint (older-expansion dungeons may
     * reference a journal_instance the operator did not sync).
     */
    public function raidInstance(): BelongsTo
    {
        return $this->belongsTo(GameDataRaidInstance::class, 'journal_instance_id');
    }
}
