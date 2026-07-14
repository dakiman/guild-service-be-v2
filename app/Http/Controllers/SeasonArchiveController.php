<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GameDataSeason;
use Illuminate\Http\JsonResponse;

class SeasonArchiveController extends Controller
{
    /**
     * GET /api/v1/stats/archive/seasons/{slug}
     *
     * The frozen M+ page payload for an archived season, exactly as
     * SeasonArchiveService wrote it. Immutable once written, hence the long
     * max-age.
     */
    public function show(string $slug): JsonResponse
    {
        $season = GameDataSeason::query()->where('slug', $slug)->first();
        $archive = $season?->archive;

        if ($archive === null) {
            return response()->json(['message' => 'No archive for this season.'], 404);
        }

        return response()->json($archive->payload)
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
