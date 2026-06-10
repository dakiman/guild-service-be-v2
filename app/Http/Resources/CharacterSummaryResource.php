<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->media;
        $avatar = is_array($media) ? ($media['avatar'] ?? null) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'realm' => $this->realm,
            'region' => $this->region,
            'display_name' => $this->display_name,
            'display_realm' => $this->display_realm,
            'class_id' => $this->class_id,
            'level' => $this->level,
            'faction' => $this->faction,
            'active_specialization' => $this->active_specialization,
            'media' => $avatar,
        ];
    }
}
