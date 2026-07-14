<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\KeystoneAffixResource;
use App\Http\Resources\MythicKeystoneDungeonResource;
use App\Http\Resources\RaidInstanceResource;
use App\Models\GameDataExpansion;
use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataRaidInstance;
use App\Models\GameDataTalentTree;
use App\Services\RealmIndexService;
use App\Support\Seasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GameDataController extends Controller
{
    /**
     * GET /api/v1/game-data/raid-instances?expansion=current|all
     *
     * Public, long-cacheable. `expansion=current` (default) scopes to the
     * latest expansion (the row with the smallest `display_order` in
     * `game_data_expansions`). `expansion=all` returns every instance.
     *
     * Response shape (no `data` envelope — matches the project convention
     * documented in frontend/CLAUDE.md):
     *   { instances: [ { id, name, display_order, media_url, expansion: {...}, encounters: [...] }, ... ] }
     *
     * Cache header per spec §2.6: `Cache-Control: public, max-age=3600`.
     */
    public function raidInstances(Request $request): JsonResponse
    {
        $expansionFilter = $request->query('expansion', 'current');

        $payload = Cache::remember("game-data:raid-instances:{$expansionFilter}", 3600, function () use ($expansionFilter) {
            $query = GameDataRaidInstance::query()
                ->with(['expansion', 'encounters'])
                ->orderBy('display_order')
                ->orderBy('id');

            if ($expansionFilter === 'current') {
                $current = GameDataExpansion::query()
                    ->orderBy('display_order')
                    ->first();

                if ($current === null) {
                    return ['instances' => []];
                }

                $query->where('expansion_id', $current->id);
            }

            return ['instances' => RaidInstanceResource::collection($query->get())->resolve()];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /api/v1/game-data/mythic-keystone-dungeons?season=current
     *
     * Returns the dungeons in the current season plus the season's affixes
     * as a dictionary keyed by id. Season scoping today only supports
     * `season=current` (per spec §2.3 — older seasons are deferred to a future
     * season-selector slice); any other value is treated the same as current.
     *
     * Response shape (no `data` envelope; affixes is a dict keyed by id so the
     * FE's `<AffixIcon :affixes="data.affixes" :affixId="..." />` can do an O(1)
     * lookup without scanning):
     *   { dungeons: [...], affixes: { "<id>": { id, name, icon_url }, ... }, season: {id, name} | null }
     *
     * Cache header per spec §2.6: `Cache-Control: public, max-age=3600`.
     */
    public function mythicKeystoneDungeons(Request $request): JsonResponse
    {
        $payload = Cache::remember('game-data:mythic-keystone-dungeons', 3600, function () {
            $dungeons = GameDataMythicKeystoneDungeon::query()
                ->orderBy('name')
                ->get();

            $affixes = GameDataKeystoneAffix::query()
                ->orderBy('id')
                ->get();

            $affixDict = [];
            foreach ($affixes as $affix) {
                $affixDict[(int) $affix->id] = (new KeystoneAffixResource($affix))->resolve();
            }

            // Populated from the game_data_seasons registry; null only when
            // the registry is empty. A non-null id activates the FE character
            // page's per-season run filtering.
            $current = Seasons::current();

            return [
                'dungeons' => MythicKeystoneDungeonResource::collection($dungeons)->resolve(),
                'affixes' => (object) $affixDict,
                'season' => $current === null ? null : ['id' => $current['id'], 'name' => $current['name']],
            ];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /api/v1/game-data/talent-trees/{treeId}/{specId}
     *
     * Public, long-cacheable. Returns the topology JSONB blob for a single
     * (tree_id, spec_id) pair. 404 when the row doesn't exist — FE treats
     * that as "not yet synced for this spec" and falls back to the
     * picked-only flat-list rendering.
     *
     * Cache header per game-data convention: `Cache-Control: public, max-age=3600`.
     */
    public function talentTree(int $treeId, int $specId): JsonResponse
    {
        $row = GameDataTalentTree::query()
            ->where('tree_id', $treeId)
            ->where('spec_id', $specId)
            ->first();

        if ($row === null) {
            return response()->json(['message' => 'Talent tree not synced for this spec yet.'], 404);
        }

        return response()->json([
            'tree_id' => (int) $row->tree_id,
            'spec_id' => (int) $row->spec_id,
            'name' => (string) $row->name,
            'tree' => $row->tree,
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /api/v1/game-data/realms
     *
     * Aggregated realm list across all configured regions for the homepage
     * realm autocomplete. Each entry: {slug, name, region}; display name is
     * slug-derived (no per-region locale handling).
     *
     * Long-cacheable (`Cache-Control: public, max-age=604800`); per-region
     * Blizzard responses are themselves cached inside BlizzardGameDataClient
     * for 7 days, so a cold call hits Blizzard 4× then warm calls are pure
     * cache reads.
     */
    public function realms(RealmIndexService $service): JsonResponse
    {
        return response()->json([
            'realms' => $service->aggregate(),
        ])->header('Cache-Control', 'public, max-age=604800');
    }
}
