<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\RefreshCooldown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Queue;

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
            'display_name' => $this->display_name,
            'display_realm' => $this->display_realm,
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
            'active_specialization_id' => $this->active_specialization_id,
            'talent_tree_id' => $this->talent_tree_id,
            'talent_loadout_code' => $this->talent_loadout_code,
            'mythic_plus_rating' => $this->mythic_plus_rating !== null
                ? [
                    'rating' => (int) $this->mythic_plus_rating,
                    'color' => $this->mythic_plus_rating_color,
                    'per_spec' => $this->perSpecForResponse(),
                ]
                : null,
            'rank' => $this->relationLoaded('rank') && $this->rank !== null
                ? (new CharacterRankResource($this->rank))->toArray($request)
                : null,
            'media' => $this->media,
            'talents' => $this->talents,
            'equipment' => $this->equipment ?? [],
            'stats' => $this->stats,
            'pvp_brackets' => PvpBracketResource::collection($this->whenLoaded('pvpBrackets')),
            'professions' => ProfessionResource::collection($this->whenLoaded('professions')),
            'raid_progress' => RaidEncounterResource::collection($this->whenLoaded('raidEncounterKills')),
            'active_title_id' => $this->active_title_id,
            'titles' => $this->when($this->resource->isEndgame(), fn () => $this->resource->resolvedTitles()),
            'reputations' => $this->when($this->resource->isEndgame(), fn () => $this->resource->resolvedReputations()),
            'recruitment' => $this->recruitment,
            'guild' => new GuildSummaryResource($this->whenLoaded('guild')),
            'dungeon_runs' => DungeonRunResource::collection($this->whenLoaded('dungeonRuns')),
            'mounts' => MountResource::collection($this->whenLoaded('mounts')),
            'pets' => PetResource::collection($this->whenLoaded('pets')),
            'toys' => ToyResource::collection($this->whenLoaded('toys')),
            'last_searched_at' => $this->last_searched_at?->toIso8601String(),
            'mythics_synced_at' => $this->mythics_synced_at?->toIso8601String(),
            'stats_synced_at' => $this->stats_synced_at?->toIso8601String(),
            'synced_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Preserve the trailing .0 on rank.percentile — plain json_encode()
     * otherwise drops it, turning a float like 4.0 into the int 4 on the wire.
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        $freshness = $this->resource->freshness();

        $isSyncing = in_array('never_synced', $freshness, true);

        $meta = [
            'game_version' => $this->game_version ?? 'retail',
            // Plumbed via $request->attributes (set in CharacterController::show)
            // rather than ->additional() — additional()'s array_merge_recursive
            // turns duplicate scalar meta keys into arrays on the wire.
            'forced_refresh' => (bool) $request->attributes->get('forced_refresh', false),
            // Identity-less models (e.g. an in-memory Character built by a unit
            // test without region/realm/name) have nothing to key a cooldown
            // on — null out rather than fatal. Every real request path always
            // has a fully-identified Character/Guild by the time it reaches
            // here, so this is purely a defensive null-safety guard.
            'refresh' => $this->region !== null && $this->realm !== null && $this->name !== null
                ? RefreshCooldown::status('character', $this->region, $this->realm, $this->name)
                : null,
            'sync_status' => $isSyncing ? 'syncing' : 'complete',
            // 'full' = endgame, slices tracked; 'basic' = sub-max, profile-only.
            // FE keys the below-max-level notice and tab gating off this.
            'profile_tier' => $this->resource->isEndgame() ? 'full' : 'basic',
            'freshness' => $freshness,
            'feature_flags' => [
                'achievements' => (bool) config('blizzard.sync.achievements_enabled'),
                'pets' => (bool) config('blizzard.sync.pets_enabled'),
                'mounts' => (bool) config('blizzard.sync.mounts_enabled'),
                'toys' => (bool) config('blizzard.sync.toys_enabled'),
            ],
        ];

        if ($isSyncing) {
            $meta['poll_after'] = 30;
            $meta['queue_depth'] = (int) Queue::size('blizzard-user-sync');
        }

        return ['meta' => $meta];
    }

    /**
     * Return per-spec as a stdClass so JsonResource::filter() does not
     * reindex the map. Integer-keyed PHP arrays get re-indexed to a
     * positional list inside the Resource pipeline regardless of whether
     * keys are sequential, which would collapse `{"258": 227}` to `[227]`
     * on the wire. Objects are left alone, so json_encode emits a JSON
     * object with string property names.
     */
    private function perSpecForResponse(): \stdClass
    {
        $out = new \stdClass;
        foreach ($this->mythic_plus_rating_by_spec ?? [] as $specId => $rating) {
            $out->{(string) $specId} = (int) $rating;
        }

        return $out;
    }
}
