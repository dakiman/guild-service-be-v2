<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\RaceFaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $id
 * @property-read int $guild_id
 * @property-read string $name
 * @property-read string $realm
 * @property-read ?string $display_name
 * @property-read ?string $display_realm
 * @property-read int $level
 * @property-read int $class_id
 * @property-read int $race_id
 * @property-read int $rank
 */
class GuildMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasCharacter = $this->relationLoaded('character') && $this->character !== null;
        $character = $hasCharacter ? $this->character : null;

        return [
            'id' => $this->id,
            'guild_id' => $this->guild_id,
            'name' => $this->name,
            'realm' => $this->realm,
            'display_name' => $this->display_name,
            'display_realm' => $this->display_realm,
            'level' => $this->level,
            'class_id' => $this->class_id,
            'race_id' => $this->race_id,
            'rank' => $this->rank,
            'faction' => RaceFaction::for($this->race_id),
            'equipped_item_level' => $hasCharacter ? $character->equipped_item_level : null,
            'mythic_plus_rating' => $hasCharacter && $character->mythic_plus_rating !== null
                ? [
                    'rating' => (int) $character->mythic_plus_rating,
                    'color' => $character->mythic_plus_rating_color,
                ]
                : null,
            'region_rank' => $hasCharacter && $character->relationLoaded('rank') ? $character->rank?->region_rank : null,
            'active_specialization_id' => $hasCharacter ? $character->active_specialization_id : null,
            'synced_at' => $hasCharacter ? $character->updated_at?->toIso8601String() : null,
        ];
    }
}
