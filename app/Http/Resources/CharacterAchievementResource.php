<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterAchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'achievement_id' => (int) $this->achievement_id,
            'completed_timestamp' => $this->completed_timestamp !== null
                ? (int) $this->completed_timestamp
                : null,
        ];
    }
}
