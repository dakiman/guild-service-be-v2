<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameDataMount extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'description',
        'source_text',
        'summon_spell_id',
        'item_id',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'summon_spell_id' => 'integer',
            'item_id' => 'integer',
        ];
    }
}
