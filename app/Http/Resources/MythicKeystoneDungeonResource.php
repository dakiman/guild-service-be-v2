<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $name
 * @property ?string $media_url
 * @property ?int $journal_instance_id
 */
class MythicKeystoneDungeonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'media_url' => $this->media_url,
            'journal_instance_id' => $this->journal_instance_id !== null
                ? (int) $this->journal_instance_id
                : null,
        ];
    }
}
