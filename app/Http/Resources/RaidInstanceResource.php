<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $name
 * @property ?int $expansion_id
 * @property int $display_order
 * @property ?string $media_url
 */
class RaidInstanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'display_order' => (int) $this->display_order,
            'media_url' => $this->media_url,
            'expansion' => $this->relationLoaded('expansion') && $this->expansion
                ? [
                    'id' => (int) $this->expansion->id,
                    'name' => $this->expansion->name,
                    'display_order' => (int) $this->expansion->display_order,
                ]
                : null,
            'encounters' => $this->whenLoaded('encounters', fn () => $this->encounters
                ->map(fn ($e) => [
                    'id' => (int) $e->id,
                    'name' => $e->name,
                    'display_order' => (int) $e->display_order,
                    'creature_display_id' => $e->creature_display_id !== null
                        ? (int) $e->creature_display_id
                        : null,
                    'portrait_url' => $e->portrait_url,
                ])
                ->values()
                ->all()),
        ];
    }
}
