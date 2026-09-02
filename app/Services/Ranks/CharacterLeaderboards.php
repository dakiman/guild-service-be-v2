<?php

declare(strict_types=1);

namespace App\Services\Ranks;

use App\Models\CharacterRank;
use Illuminate\Database\Eloquent\Builder;

class CharacterLeaderboards
{
    public const CAP = 100;

    public const SCOPES = ['world', 'region', 'realm', 'class', 'spec'];

    /** @return array{rows: list<array<string, mixed>>, population: int, season_id: ?int} */
    public function top(string $scope, ?string $region, ?int $connectedRealmId, ?int $classId, ?int $specId): array
    {
        $rankColumn = "{$scope}_rank";
        $popColumn = "{$scope}_pop";

        $query = CharacterRank::query()
            ->join('characters', 'characters.id', '=', 'character_ranks.character_id')
            ->select('character_ranks.*')
            ->with('character:id,name,display_name,realm,display_realm,region,class_id,active_specialization_id,faction,mythic_plus_rating_color')
            ->whereNotNull("character_ranks.{$rankColumn}")
            ->orderBy("character_ranks.{$rankColumn}")
            ->orderBy('characters.name')
            ->limit(self::CAP);

        $this->applyScope($query, $scope, $region, $connectedRealmId, $classId, $specId);

        $ranks = $query->get();

        return [
            'rows' => $ranks->map(fn (CharacterRank $r) => [
                'rank' => (int) $r->{$rankColumn},
                'rating' => (int) $r->rating,
                'color' => $r->character->mythic_plus_rating_color,
                'character' => [
                    'name' => $r->character->name,
                    'display_name' => $r->character->display_name,
                    'realm' => $r->character->realm,
                    'display_realm' => $r->character->display_realm,
                    'region' => $r->character->region,
                    'class_id' => $r->character->class_id,
                    'spec_id' => $r->character->active_specialization_id,
                    'faction' => $r->character->faction,
                ],
            ])->values()->all(),
            'population' => $ranks->isEmpty() ? 0 : (int) $ranks->first()->{$popColumn},
            'season_id' => $ranks->isEmpty() ? null : (int) $ranks->first()->season_id,
        ];
    }

    private function applyScope(Builder $query, string $scope, ?string $region, ?int $connectedRealmId, ?int $classId, ?int $specId): void
    {
        if ($scope === 'world') {
            return;
        }
        $query->where('character_ranks.region', $region);
        match ($scope) {
            'realm' => $query->where('character_ranks.connected_realm_id', $connectedRealmId),
            'class' => $query->where('character_ranks.class_id', $classId),
            'spec' => $query->where('character_ranks.spec_id', $specId),
            default => null,
        };
    }
}
