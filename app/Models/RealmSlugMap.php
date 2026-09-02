<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealmSlugMap extends Model
{
    protected $table = 'realm_slug_map';

    public $timestamps = false;

    protected $fillable = ['region', 'realm_slug', 'connected_realm_id'];

    protected function casts(): array
    {
        return ['connected_realm_id' => 'integer'];
    }
}
