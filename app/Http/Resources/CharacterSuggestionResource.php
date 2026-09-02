<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'region' => $this->region,
            'realm' => $this->realm,
            'display_realm' => $this->display_realm,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'class_id' => $this->class_id,
            'level' => $this->level,
            'faction' => $this->faction,
            'mythic_plus_rating' => $this->mythic_plus_rating !== null
                ? ['rating' => (int) $this->mythic_plus_rating, 'color' => $this->mythic_plus_rating_color] + $this->ratingSeasonBlock()
                : null,
            'region_rank' => $this->relationLoaded('rank') ? $this->rank?->region_rank : null,
        ];
    }
}
