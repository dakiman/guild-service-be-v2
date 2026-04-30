<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => (int) $this->title_id,
            'name' => $this->name,
            'display_string' => $this->display_string,
            'is_selected' => (bool) $this->is_selected,
        ];

        // Only include game_data block if the relation is loaded AND the game data exists
        if ($this->relationLoaded('gameData') && $this->gameData !== null) {
            $data['game_data'] = [
                'name_male' => $this->gameData->name_male,
                'name_female' => $this->gameData->name_female,
            ];
        }

        return $data;
    }
}
