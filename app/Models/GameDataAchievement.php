<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataAchievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'description',
        'category_id',
        'points',
        'is_account_wide',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'category_id' => 'integer',
            'points' => 'integer',
            'is_account_wide' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GameDataAchievementCategory::class, 'category_id');
    }
}
