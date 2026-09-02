<?php

declare(strict_types=1);

namespace App\Services\Ranks;

use App\Models\GameDataConnectedRealm;
use App\Models\RealmSlugMap;
use Illuminate\Support\Facades\DB;

class RealmSlugMapBuilder
{
    /**
     * Flatten game_data_connected_realms.realm_slugs into (region, slug) → group id.
     * Full replace inside a transaction so readers never see a half-built map.
     */
    public function rebuild(): int
    {
        $rows = [];
        foreach (GameDataConnectedRealm::query()->get() as $group) {
            foreach ($group->realm_slugs ?? [] as $slug) {
                $rows[] = [
                    'region' => $group->region,
                    'realm_slug' => $slug,
                    'connected_realm_id' => $group->connected_realm_id,
                ];
            }
        }

        DB::transaction(function () use ($rows) {
            RealmSlugMap::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                RealmSlugMap::insert($chunk);
            }
        });

        return count($rows);
    }
}
