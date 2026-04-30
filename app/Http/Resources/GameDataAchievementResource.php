<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameDataAchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'points' => (int) $this->points,
            'is_account_wide' => (bool) $this->is_account_wide,
        ];

        // Manual relationLoaded + null check (matches CharacterTitleResource /
        // ReputationResource / MountResource): whenLoaded() returns plain null
        // when the belongsTo is loaded-but-null, which would emit
        // "category": null instead of omitting the key.
        if ($this->relationLoaded('category') && $this->category !== null) {
            $data['category'] = [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
                'display_order' => (int) $this->category->display_order,
            ];
        }

        return $data;
    }
}
