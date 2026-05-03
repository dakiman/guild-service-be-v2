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
            'members' => $this->whenLoaded('memberEntries', function () {
                return $this->memberEntries->map(function ($member) {
                    return [
                        'character_id' => $member->character_id,
                        'character_name' => $member->character_name,
                        'character_realm' => $member->character_realm,
                        'character_realm_display' => $member->display_realm,
                        'character_region' => $member->character_region,
                        'spec_id' => $member->spec_id,
                        'spec_name' => $member->spec_name,
                        'equipped_item_level' => $member->equipped_item_level,
                    ];
                });
            }),
        ];
    }
}
