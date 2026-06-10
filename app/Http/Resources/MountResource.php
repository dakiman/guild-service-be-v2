<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'mount_id' => (int) $this->mount_id,
            'name' => $this->name,
            'is_useable' => (bool) $this->is_useable,
        ];

        // Only include game_data when the relation is loaded AND the row
        // exists. whenLoaded() returns plain null (not MissingValue) when
        // the belongsTo is loaded-but-null and would emit "game_data": null
        // instead of omitting the key — same pattern as CharacterResource.
        if ($this->relationLoaded('gameData') && $this->gameData !== null) {
            $data['game_data'] = [
                'description' => $this->gameData->description,
                'source_text' => $this->gameData->source_text,
                'summon_spell_id' => $this->gameData->summon_spell_id !== null
                    ? (int) $this->gameData->summon_spell_id
                    : null,
                'item_id' => $this->gameData->item_id !== null
                    ? (int) $this->gameData->item_id
                    : null,
            ];
        }

        return $data;
    }
}
