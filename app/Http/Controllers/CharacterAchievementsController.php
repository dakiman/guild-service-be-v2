<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Support\BlizzardIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CharacterAchievementsController extends Controller
{
    private const FEATS_OF_STRENGTH_CATEGORY = 'Feats of Strength';

    private const DEFAULT_PER_PAGE = 100;

    private const MAX_PER_PAGE = 200;

    /**
     * GET /api/v1/characters/{region}/{realm}/{name}/achievements
     *
     * Cursor-paginated achievements list, joined to game_data for name +
     * category. Sorted recent-first. Drops Feats of Strength unless
     * include_feats=1.
     *
     * Query params:
     *   - per_page: int, default 100, max 200
     *   - cursor: opaque string from previous response's `next_cursor`
     *   - include_feats: 0|1, default 0
     */
    public function index(string $region, string $realm, string $name, Request $request): JsonResponse
    {
        $realm = BlizzardIdentity::realm($realm);
        $name = BlizzardIdentity::name($name);

        $character = Character::query()->byIdentity($name, $realm, $region)->first();

        if ($character === null) {
            return response()->json(['message' => 'Character not found'], 404);
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE)));
        $includeFeats = (bool) $request->query('include_feats', false);
        $cursor = $this->decodeCursor((string) $request->query('cursor', ''));

        $base = DB::table('character_achievements as ca')
            ->leftJoin('game_data_achievements as gda', 'gda.id', '=', 'ca.achievement_id')
            ->leftJoin('game_data_achievement_categories as gdac', 'gdac.id', '=', 'gda.category_id')
            ->where('ca.character_id', $character->id);

        if (! $includeFeats) {
            $base->where(function ($q) {
                $q->whereNull('gdac.name')->orWhere('gdac.name', '!=', self::FEATS_OF_STRENGTH_CATEGORY);
            });
        }

        $total = (clone $base)->count();

        $rows = $base
            ->select([
                'ca.achievement_id',
                'ca.completed_timestamp',
                'gda.name',
                'gdac.name as category_name',
            ])
            // NULLS LAST + tiebreaker on achievement_id desc for stable cursor pagination.
            ->orderByRaw('ca.completed_timestamp IS NULL, ca.completed_timestamp DESC, ca.achievement_id DESC');

        if ($cursor !== null) {
            $rows->where(function ($q) use ($cursor) {
                if ($cursor['ts'] === null) {
                    // We are already in the NULL-timestamp tail; only paginate by achievement_id.
                    $q->whereNull('ca.completed_timestamp')->where('ca.achievement_id', '<', $cursor['id']);
                } else {
                    // Either still in the non-null prefix (older ts, or same ts with smaller id),
                    // or already crossed into the NULL tail.
                    $q->where(function ($inner) use ($cursor) {
                        $inner->where('ca.completed_timestamp', '<', $cursor['ts'])
                            ->orWhere(function ($i) use ($cursor) {
                                $i->where('ca.completed_timestamp', $cursor['ts'])
                                    ->where('ca.achievement_id', '<', $cursor['id']);
                            });
                    })->orWhereNull('ca.completed_timestamp');
                }
            });
        }

        $items = $rows->limit($perPage + 1)->get();

        $hasMore = $items->count() > $perPage;
        if ($hasMore) {
            $items = $items->slice(0, $perPage)->values();
        }

        $nextCursor = null;
        if ($hasMore) {
            $last = $items->last();
            $nextCursor = $this->encodeCursor(
                $last->completed_timestamp !== null ? (int) $last->completed_timestamp : null,
                (int) $last->achievement_id,
            );
        }

        $data = $items->map(fn ($row) => [
            'achievement_id' => (int) $row->achievement_id,
            'completed_timestamp' => $row->completed_timestamp !== null ? (int) $row->completed_timestamp : null,
            'name' => $row->name,
            'category_name' => $row->category_name,
        ])->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'next_cursor' => $nextCursor,
            ],
        ]);
    }

    /**
     * @return array{ts: int|null, id: int}|null
     */
    private function decodeCursor(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ! array_key_exists('id', $payload)) {
            return null;
        }

        return [
            'ts' => isset($payload['ts']) && $payload['ts'] !== null ? (int) $payload['ts'] : null,
            'id' => (int) $payload['id'],
        ];
    }

    private function encodeCursor(?int $ts, int $id): string
    {
        $payload = json_encode(['ts' => $ts, 'id' => $id]);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
