<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CharacterRank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read CharacterRank $resource */
class CharacterRankResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'season_id' => $r->season_id,
            'rating' => $r->rating,
            'world' => $r->world_rank,
            'region' => $r->region_rank,
            'realm' => $r->realm_rank,
            'class' => $r->class_rank,
            'spec' => $r->spec_rank,
            'population' => [
                'world' => $r->world_pop,
                'region' => $r->region_pop,
                'realm' => $r->realm_pop,
                'class' => $r->class_pop,
                'spec' => $r->spec_pop,
            ],
            'percentile' => $r->region_pop > 0 ? round(100 * $r->region_rank / $r->region_pop, 1) : null,
            'connected_realm_id' => $r->connected_realm_id,
            'computed_at' => $r->computed_at?->toIso8601String(),
        ];
    }
}
