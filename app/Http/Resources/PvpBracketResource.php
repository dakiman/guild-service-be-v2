<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PvpBracketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'bracket' => $this->bracket,
            'rating' => $this->rating,
            'tier_name' => $this->tier_name,
            'season' => [
                'played' => $this->season_played,
                'won' => $this->season_won,
                'lost' => $this->season_lost,
            ],
            'weekly' => [
                'played' => $this->weekly_played,
                'won' => $this->weekly_won,
                'lost' => $this->weekly_lost,
            ],
        ];
    }
}
