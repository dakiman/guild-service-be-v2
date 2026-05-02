<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataTalentTree extends Model
{
    protected $table = 'game_data_talent_trees';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'tree_id',
        'spec_id',
        'name',
        'tree',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tree_id' => 'integer',
            'spec_id' => 'integer',
            'tree' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
