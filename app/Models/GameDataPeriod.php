<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataPeriod extends Model
{
    protected $fillable = ['period_id', 'region', 'start_at', 'end_at'];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public static function currentFor(string $region): ?self
    {
        return self::query()
            ->where('region', $region)
            ->where('start_at', '<=', now())
            ->orderByDesc('period_id')
            ->first();
    }
}
