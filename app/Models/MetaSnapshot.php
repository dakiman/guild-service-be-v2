<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaSnapshot extends Model
{
    protected $fillable = ['period_id', 'region', 'section', 'payload', 'computed_at'];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'payload' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
