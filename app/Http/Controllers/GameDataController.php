<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\GameDataAchievementResource;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameDataController extends Controller
{
    /**
     * GET /api/v1/game-data/achievements
     *
     * Returns the full achievements catalog (~40k rows) joined to categories.
     * Designed to be fetched once per session by the FE; HTTP-cached for 24h
     * + ETag-based 304 revalidation.
     */
    public function achievements(Request $request): JsonResponse
    {
        $etag = $this->etag();

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch === $etag) {
            return response()->json(null, 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=86400');
        }

        $achievements = GameDataAchievement::query()
            ->with('category')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => GameDataAchievementResource::collection($achievements),
        ])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Build a stable ETag from the most recent updated_at across the two tables
     * the achievements endpoint depends on.
     */
    private function etag(): string
    {
        $achMax = GameDataAchievement::max('updated_at');
        $catMax = GameDataAchievementCategory::max('updated_at');
        $token = ($achMax ?? 'none').'|'.($catMax ?? 'none');

        return '"'.sha1($token).'"';
    }
}
