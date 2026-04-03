<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
            'gender' => $this->gender,
            'faction' => $this->faction,
            'race_id' => $this->race_id,
            'class_id' => $this->class_id,
            'level' => $this->level,
            'achievement_points' => $this->achievement_points,
            'average_item_level' => $this->average_item_level,
            'equipped_item_level' => $this->equipped_item_level,
            'active_specialization' => $this->active_specialization,
            'media' => $this->media,
            'talents' => $this->talents,
            'equipment' => $this->equipment,
            'recruitment' => $this->recruitment,
            'guild' => new GuildSummaryResource($this->whenLoaded('guild')),
            'dungeon_runs' => DungeonRunResource::collection($this->whenLoaded('dungeonRuns')),
            'synced_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
