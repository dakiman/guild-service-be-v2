<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterResource extends JsonResource
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
            'game_version' => $this->game_version ?? 'retail',
            'gender' => $this->gender,
            'faction' => $this->faction,
            'race_id' => $this->race_id,
            'class_id' => $this->class_id,
            'level' => $this->level,
            'achievement_points' => $this->achievement_points,
            'average_item_level' => $this->average_item_level,
            'equipped_item_level' => $this->equipped_item_level,
            'active_specialization' => $this->active_specialization,
            'talent_loadout_code' => $this->talent_loadout_code,
            'mythic_plus_rating' => $this->mythic_plus_rating !== null
                ? [
                    'rating' => (int) $this->mythic_plus_rating,
                    'per_spec' => $this->mythic_plus_rating_by_spec ?? [],
                ]
                : null,
            'media' => $this->media,
            'talents' => $this->talents,
            'equipment' => $this->equipment ?? [],
            'pvp_brackets' => null,
            'professions' => null,
            'raid_progress' => null,
            'recruitment' => $this->recruitment,
            'guild' => new GuildSummaryResource($this->whenLoaded('guild')),
            'dungeon_runs' => DungeonRunResource::collection($this->whenLoaded('dungeonRuns')),
            'last_searched_at' => $this->last_searched_at?->toIso8601String(),
            'mythics_synced_at' => $this->mythics_synced_at?->toIso8601String(),
            'synced_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'game_version' => $this->game_version ?? 'retail',
                'forced_refresh' => false,
                'freshness' => [
                    'profile' => $this->freshnessFor('updated_at', 'profile'),
                    'mythic_plus' => $this->freshnessFor('mythics_synced_at', 'mythic_plus'),
                    'pvp' => $this->freshnessFor('pvp_synced_at', 'pvp'),
                    'professions' => $this->freshnessFor('professions_synced_at', 'professions'),
                    'raids' => $this->freshnessFor('raids_synced_at', 'raids'),
                ],
            ],
        ];
    }

    private function freshnessFor(string $timestampField, string $configKey): string
    {
        $ts = $this->resource->{$timestampField} ?? null;
        if ($ts === null) {
            return 'never_synced';
        }

        $threshold = (int) config("blizzard.staleness.character.{$configKey}", 900);

        return $ts->diffInSeconds(now()) > $threshold ? 'stale' : 'fresh';
    }
}
