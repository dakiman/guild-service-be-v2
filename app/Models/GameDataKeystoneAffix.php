<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameDataKeystoneAffix extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'icon_url',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }
}
