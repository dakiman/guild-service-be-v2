<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataFaction extends Model
{
    use HasFactory;

    protected $fillable = ['id', 'name', 'parent_faction_id', 'expansion_id'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'parent_faction_id' => 'integer',
            'expansion_id' => 'integer',
        ];
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(GameDataExpansion::class, 'expansion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_faction_id');
    }
}
