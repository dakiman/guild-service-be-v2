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
        $data = [
            'faction_id' => $this->faction_id,
            'faction_name' => $this->faction_name,
            'standing' => $this->standing,
            'value' => $this->value,
            'max' => $this->max,
        ];

        // Only include the faction block when the relation is loaded AND
        // the row exists. whenLoaded() returns plain null (not MissingValue)
        // when the relation is loaded-but-null, which would emit
        // "faction": null instead of omitting the key — and the FE contract
        // expects the key to be absent when there's no game-data row.
        if ($this->relationLoaded('faction') && $this->faction !== null) {
            $data['faction'] = [
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
            ];
        }

        return $data;
    }
}
