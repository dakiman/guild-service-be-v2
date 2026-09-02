<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LeaderboardCharactersRequest;
use App\Http\Requests\RealmRunsRequest;
use App\Models\GameDataPeriod;
use App\Models\RealmRunBoard;
use App\Models\RealmSlugMap;
use App\Services\Ranks\CharacterLeaderboards;
use App\Services\Ranks\RankMaterializer;
use App\Support\BlizzardIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class LeaderboardController extends Controller
{
    public function characters(LeaderboardCharactersRequest $request, CharacterLeaderboards $leaderboards): JsonResponse
    {
        $scope = (string) $request->input('scope');
        $region = $request->input('region');
        $realm = $request->filled('realm') ? BlizzardIdentity::realm((string) $request->input('realm')) : null;
        $classId = $request->filled('class_id') ? (int) $request->input('class_id') : null;
        $specId = $request->filled('spec_id') ? (int) $request->input('spec_id') : null;

        $connectedRealmId = null;
        if ($scope === 'realm') {
            $connectedRealmId = $this->connectedRealmId($region, $realm);
            if ($connectedRealmId === null) {
                return response()->json(['message' => 'Unknown realm'], 404);
            }
        }

        $stamp = $this->stamp();
        $key = "leaderboards:chars:{$stamp}:{$scope}:".($region ?? 'all').':'.($connectedRealmId ?? $classId ?? $specId ?? 'all');

        // Plain array only — cache.serializable_classes is false.
        $payload = Cache::flexible($key, [270, 86400], function () use ($leaderboards, $scope, $region, $connectedRealmId, $classId, $specId, $realm, $stamp) {
            $result = $leaderboards->top($scope, $region, $connectedRealmId, $classId, $specId);

            return [
                'data' => $result['rows'],
                'meta' => [
                    'scope' => $scope,
                    'region' => $region,
                    'realm' => $realm,
                    'connected_realm_id' => $connectedRealmId,
                    'class_id' => $classId,
                    'spec_id' => $specId,
                    'season_id' => $result['season_id'],
                    'population' => $result['population'],
                    'computed_at' => $stamp === 'none' ? null : $stamp,
                ],
            ];
        });

        return response()->json($payload);
    }

    public function realmRuns(RealmRunsRequest $request): JsonResponse
    {
        $region = (string) $request->input('region');
        $realm = BlizzardIdentity::realm((string) $request->input('realm'));
        $connectedRealmId = $this->connectedRealmId($region, $realm);
        if ($connectedRealmId === null) {
            return response()->json(['message' => 'Unknown realm'], 404);
        }

        $period = GameDataPeriod::currentFor($region);
        $periodId = $period?->period_id;
        $stamp = $this->stamp();
        $key = "leaderboards:realm-runs:{$stamp}:{$region}:{$connectedRealmId}:".($periodId ?? 'none');

        $payload = Cache::flexible($key, [270, 86400], function () use ($region, $realm, $connectedRealmId, $periodId) {
            $board = $periodId === null ? null : RealmRunBoard::query()
                ->where('region', $region)->where('period_id', $periodId)
                ->where('connected_realm_id', $connectedRealmId)->first();

            return [
                'data' => $board?->payload ?? [],
                'meta' => [
                    'period_id' => $periodId,
                    'region' => $region,
                    'realm' => $realm,
                    'connected_realm_id' => $connectedRealmId,
                    'computed_at' => $board?->computed_at?->toIso8601String(),
                ],
            ];
        });

        return response()->json($payload);
    }

    private function connectedRealmId(?string $region, ?string $realm): ?int
    {
        if ($region === null || $realm === null) {
            return null;
        }
        $id = RealmSlugMap::query()->where('region', $region)->where('realm_slug', $realm)->value('connected_realm_id');

        return $id === null ? null : (int) $id;
    }

    private function stamp(): string
    {
        return (string) (Cache::get(RankMaterializer::STAMP_KEY) ?? 'none');
    }
}
