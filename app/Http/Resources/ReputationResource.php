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
        ];
    }
}
