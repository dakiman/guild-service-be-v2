<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'profession_id' => $this->profession_id,
            'profession_name' => $this->profession_name,
            'tier_name' => $this->tier_name,
            'skill_points' => $this->skill_points,
            'max_skill_points' => $this->max_skill_points,
            'is_primary' => $this->is_primary,
            // whenLoaded() returns plain null (not MissingValue) when the
            // belongsTo is loaded-but-null, which would emit
            // "expansion": null. We explicitly null-check so the FE's
            // Legacy fallback (treats null as bucket order 99) works.
            'expansion' => $this->relationLoaded('expansion') && $this->expansion
                ? [
                    'id' => $this->expansion->id,
                    'name' => $this->expansion->name,
                    'display_order' => $this->expansion->display_order,
                ]
                : null,
        ];
    }
}
