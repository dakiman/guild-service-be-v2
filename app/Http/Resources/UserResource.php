<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'bnet_id' => $this->bnet_id,
            'bnet_tag' => $this->bnet_tag,
            'bnet_region' => $this->bnet_region,
            'bnet_synced_at' => $this->bnet_synced_at,
            'bnet_sync_status' => $this->bnet_sync_status,
            'characters' => CharacterSummaryResource::collection($this->whenLoaded('characters')),
        ];
    }
}
