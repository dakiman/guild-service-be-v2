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
        return [
            'mount_id' => (int) $this->mount_id,
            'name' => $this->name,
            'is_useable' => (bool) $this->is_useable,
        ];
    }
}
