<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RaidEncounterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'expansion' => $this->expansion_name,
            'instance_id' => $this->instance_id,
            'instance_name' => $this->instance_name,
            'encounter_id' => $this->encounter_id,
            'encounter_name' => $this->encounter_name,
            'difficulty' => $this->difficulty,
            'completed_count' => $this->completed_count,
            'last_kill_timestamp' => $this->last_kill_timestamp,
        ];
    }
}
