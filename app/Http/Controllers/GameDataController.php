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
use App\Services\RealmIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $query = GameDataRaidInstance::query()
            ->with(['expansion', 'encounters'])
            ->orderBy('display_order')
            ->orderBy('id');

        if ($expansionFilter === 'current') {
            $current = GameDataExpansion::query()
                ->orderBy('display_order')
                ->first();

            if ($current === null) {
                // No expansion data yet — return an empty payload rather than
                // an error so the FE can render an empty state cleanly.
                return response()->json(['instances' => []])
                    ->header('Cache-Control', 'public, max-age=3600');
            }

            $query->where('expansion_id', $current->id);
        }
        // 'all' => no scope filter applied.

        $instances = $query->get();

        return response()->json([
            'instances' => RaidInstanceResource::collection($instances)->resolve(),
        ])->header('Cache-Control', 'public, max-age=3600');
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
     *   { dungeons: [...], affixes: { "<id>": { id, name, icon_url }, ... }, season: null }
     *
     * `season` is null today — Blizzard's mythic-keystone/season endpoint exposes
     * an id but no display name; populating this is deferred to a follow-up.
     *
     * Cache header per spec §2.6: `Cache-Control: public, max-age=3600`.
     */
    public function mythicKeystoneDungeons(Request $request): JsonResponse
    {
        // Season is implicit: the sync command repopulates
        // game_data_mythic_keystone_dungeons each run with the current season.
        // We return whatever is in the table.
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

        return response()->json([
            'dungeons' => MythicKeystoneDungeonResource::collection($dungeons)->resolve(),
            'affixes' => (object) $affixDict,
            'season' => null,
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /api/v1/game-data/realms
     *
     * Aggregated realm list across all configured regions for the homepage
     * realm autocomplete. Each entry: {slug, name, region}; display name is
     * slug-derived (no per-region locale handling).
     *
     * Long-cacheable (`Cache-Control: public, max-age=3600`); per-region
     * Blizzard responses are themselves cached inside BlizzardGameDataClient
     * for 7 days, so a cold call hits Blizzard 4× then warm calls are pure
     * cache reads.
     */
    public function realms(RealmIndexService $service): JsonResponse
    {
        return response()->json([
            'realms' => $service->aggregate(),
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}
