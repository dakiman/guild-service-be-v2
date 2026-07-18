<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Jobs\SyncGuildData;
use App\Exceptions\EntityNotFoundException;
use App\Http\Resources\GuildMemberResource;
use App\Http\Resources\GuildResource;
use App\Http\Resources\GuildSuggestionResource;
use App\Models\Guild;
use App\Services\GuildService;
use App\Support\BlizzardIdentity;
use App\Support\RefreshCooldown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class GuildController extends Controller
{
    public function show(string $region, string $realm, string $guild, GuildService $service, Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'string', 'max:64'],
        ]);

        $realm = BlizzardIdentity::realm($realm);
        $guild = BlizzardIdentity::realm($guild);

        // Cooldown grant claimed here (atomic Cache::add) — same pattern as
        // CharacterController::show.
        $granted = $request->boolean('refresh') && RefreshCooldown::attempt('guild', $region, $realm, $guild);
        $request->attributes->set('forced_refresh', $granted);

        try {
            $result = $service->getByIdentity($region, $realm, $guild, forceRefresh: $granted);
        } catch (EntityNotFoundException) {
            return response()->json(['message' => 'Guild not found'], 404);
        }

        if ($result === null) {
            SyncGuildData::dispatch($region, $realm, $guild);

            // SyncGuildData runs on blizzard-user-sync — same lane the FE is
            // effectively waiting on, so its depth is the honest signal here.
            $queueDepth = (int) Queue::size('blizzard-user-sync');

            return response()->json([
                'message' => 'Guild sync initiated',
                'queue_depth' => $queueDepth,
            ], 202)->header('Retry-After', $queueDepth > 100 ? '30' : '5');
        }

        $perPage = min(100, (int) $request->query('per_page', '50'));
        $filter = trim((string) $request->query('filter', ''));

        // Self-heal any guild_members rows whose character_id is still NULL
        // but a matching Character now exists (e.g., synced via teammate
        // crawl after the last SyncGuildData run). Idempotent — once linked,
        // the WHERE filters everything out and no rows are touched. Throttled
        // so the scan doesn't run on every page view. (P2.4)
        $result->backfillMemberCharacterIds(throttled: true);

        $query = $result->members()
            ->with(['character:id,equipped_item_level,mythic_plus_rating,mythic_plus_rating_color,active_specialization_id,updated_at'])
            // Stable order: without it Postgres returns arbitrary order and
            // pages can repeat or skip rows across requests. (P1.11)
            ->orderBy('rank')
            ->orderBy('name')
            ->orderBy('id');

        if ($filter !== '') {
            // Names are stored canonical-lowercase; LIKE is case-correct on Postgres.
            $query->where('name', 'LIKE', '%'.strtolower($filter).'%');
        }

        $members = $query->paginate($perPage);
        $members = $members->through(fn ($member) => (new GuildMemberResource($member))->toArray($request));

        $response = response()->json([
            'guild' => new GuildResource($result),
            'members' => $members,
            'meta' => [
                'forced_refresh' => $granted,
                'refresh' => RefreshCooldown::status('guild', $region, $realm, $guild),
            ],
        ]);

        if ($result->isStale()) {
            $response->header('X-Data-Staleness', 'stale');
        }

        if ($result->roster_synced_at === null) {
            $response->header('X-Sync-Status', 'syncing');
            $response->header('Retry-After', '30');
        }

        return $response;
    }

    public function popular(GuildService $service): JsonResponse
    {
        return response()->json($service->getPopular());
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => 'present|nullable|string|max:64']);

        $rows = Guild::nameSearch((string) $request->query('q'))->get();

        return response()->json([
            'suggestions' => GuildSuggestionResource::collection($rows),
        ]);
    }

    public function discover(GuildService $service): JsonResponse
    {
        return response()->json($service->getDiscover());
    }
}
