<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReputationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'faction_id' => $this->faction_id,
            'faction_name' => $this->faction_name,
            'standing' => $this->standing,
            'value' => $this->value,
            'max' => $this->max,
            'faction' => $this->whenLoaded('faction', fn () => [
                'id' => $this->faction->id,
                'name' => $this->faction->name,
                'parent_faction_id' => $this->faction->parent_faction_id,
                'expansion' => $this->faction->relationLoaded('expansion') && $this->faction->expansion
                    ? [
                        'id' => $this->faction->expansion->id,
                        'name' => $this->faction->expansion->name,
                        'display_order' => $this->faction->expansion->display_order,
                    ]
                    : null,
            ]),
        ];
    }
}
