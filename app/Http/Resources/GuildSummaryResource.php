<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuildSummaryResource extends JsonResource
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
            'display_name' => $this->display_name,
            'display_realm' => $this->display_realm,
            'faction' => $this->faction,
        ];
    }
}
