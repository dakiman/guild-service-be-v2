<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDataExpansion extends Model
{
    protected $fillable = ['id', 'name', 'display_order'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function factions(): HasMany
    {
        return $this->hasMany(GameDataFaction::class, 'expansion_id');
    }
}
