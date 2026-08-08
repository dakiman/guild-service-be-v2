<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataConnectedRealm extends Model
{
    protected $fillable = ['connected_realm_id', 'region', 'realm_slugs'];

    protected function casts(): array
    {
        return [
            'connected_realm_id' => 'integer',
            'realm_slugs' => 'array',
        ];
    }
}
