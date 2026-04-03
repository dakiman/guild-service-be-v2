<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DungeonRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'season' => $this->season,
            'dungeon_id' => $this->dungeon_id,
            'dungeon_name' => $this->dungeon_name,
            'keystone_level' => $this->keystone_level,
            'duration' => $this->duration,
            'completed_timestamp' => $this->completed_timestamp,
            'is_completed_on_time' => $this->is_completed_on_time,
            'affixes' => $this->affixes,
            'members' => $this->whenLoaded('members', function () {
                return $this->members->map(function ($member) {
                    return [
                        'character_id' => $member->pivot->character_id,
                        'character_name' => $member->pivot->character_name,
                        'character_realm' => $member->pivot->character_realm,
                        'character_region' => $member->pivot->character_region,
                        'spec_id' => $member->pivot->spec_id,
                        'spec_name' => $member->pivot->spec_name,
                        'equipped_item_level' => $member->pivot->equipped_item_level,
                    ];
                });
            }),
        ];
    }
}
