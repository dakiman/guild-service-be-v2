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
        return [
            'id' => (int) $this->title_id,
            'name' => $this->name,
            'display_string' => $this->display_string,
            'is_selected' => (bool) $this->is_selected,
        ];
    }
}
