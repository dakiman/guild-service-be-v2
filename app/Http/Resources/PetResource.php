<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'pet_id' => (int) $this->pet_id,
            'species_id' => (int) $this->species_id,
            'name' => $this->name,
            'level' => (int) $this->level,
            'breed_id' => $this->breed_id !== null ? (int) $this->breed_id : null,
            'quality' => $this->quality,
            'is_favorite' => (bool) $this->is_favorite,
            'creature_display_id' => $this->creature_display_id !== null ? (int) $this->creature_display_id : null,
        ];
    }
}
