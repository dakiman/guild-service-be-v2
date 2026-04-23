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
        ];
    }
}
