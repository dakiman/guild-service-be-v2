<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LadderRunMember extends Model
{
    protected $fillable = [
        'ladder_run_id', 'profile_id', 'name', 'realm_slug',
        'realm_id', 'faction', 'spec_id',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'realm_id' => 'integer',
            'spec_id' => 'integer',
        ];
    }

    public function ladderRun(): BelongsTo
    {
        return $this->belongsTo(LadderRun::class);
    }
}
