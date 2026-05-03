<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeededRun extends Model
{
    protected $table = 'seeded_runs';

    protected $primaryKey = 'keystone_run_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'keystone_run_id',
        'region',
        'seeded_at',
    ];

    protected function casts(): array
    {
        return [
            'keystone_run_id' => 'integer',
            'seeded_at' => 'datetime',
        ];
    }
}
