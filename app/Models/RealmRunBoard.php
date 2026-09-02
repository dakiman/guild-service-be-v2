<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealmRunBoard extends Model
{
    protected $fillable = ['period_id', 'region', 'connected_realm_id', 'payload', 'computed_at'];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'connected_realm_id' => 'integer',
            'payload' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
