<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataPeriod extends Model
{
    protected $fillable = ['period_id', 'region', 'start_at', 'end_at', 'affix_ids'];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'affix_ids' => 'array',
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

    /**
     * The most recent period whose reset already happened, but only if it ended
     * within the last $withinHours — used to finalize a week after its reset.
     */
    public static function recentlyEndedFor(string $region, int $withinHours): ?self
    {
        return self::query()
            ->where('region', $region)
            ->where('end_at', '<=', now())
            ->where('end_at', '>=', now()->subHours($withinHours))
            ->orderByDesc('period_id')
            ->first();
    }
}
