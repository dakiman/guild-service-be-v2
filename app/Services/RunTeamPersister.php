<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Support\BlizzardIdentity;
use Illuminate\Support\Facades\DB;

class RunTeamPersister
{
    /**
     * @param  array<int, array{name: string, realm: string, realm_name?: ?string, specialization_id?: ?int, specialization: ?string, equipped_item_level: ?int}>  $team
     */
    public function syncTeam(DungeonRun $run, array $team, string $region): void
    {
        $now = now();
        $keep = [];

        // Batch-resolve character_id for the whole team in one query (was one
        // SELECT per member). The three-column whereIn may over-fetch; the exact
        // canonical-key match below filters. (P2.1)
        $idMap = $this->resolveCharacterIds($team, $region);

        $rows = [];
        foreach ($team as $member) {
            $resolvedId = $idMap[BlizzardIdentity::name($member['name']).'|'.$member['realm']] ?? null;

            $rows[] = [
                'dungeon_run_id' => $run->id,
                'character_name' => $member['name'],
                'character_realm' => $member['realm'],
                'character_region' => $region,
                'character_id' => $resolvedId,
                'display_realm' => $member['realm_name'] ?? null,
                'spec_id' => $member['specialization_id'] ?? null,
                'spec_name' => $member['specialization'],
                'equipped_item_level' => $member['equipped_item_level'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $keep[] = [
                'name' => $member['name'],
                'realm' => $member['realm'],
                'region' => $region,
            ];
        }

        if ($rows !== []) {
            // created_at omitted from the update set so re-syncs don't churn it.
            DB::table('dungeon_run_members')->upsert(
                $rows,
                ['dungeon_run_id', 'character_name', 'character_realm', 'character_region'],
                ['character_id', 'display_realm', 'spec_id', 'spec_name', 'equipped_item_level', 'updated_at'],
            );
        }

        $existing = DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->get(['id', 'character_name', 'character_realm', 'character_region']);

        $keepKey = fn (string $n, string $r, string $reg) => "{$n}|{$r}|{$reg}";
        $keepSet = collect($keep)
            ->mapWithKeys(fn ($k) => [$keepKey($k['name'], $k['realm'], $k['region']) => true])
            ->all();

        $toDelete = $existing
            ->reject(fn ($row) => isset($keepSet[$keepKey($row->character_name, $row->character_realm, $row->character_region)]))
            ->pluck('id')
            ->all();

        if ($toDelete !== []) {
            DB::table('dungeon_run_members')->whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * Resolve tracked character ids for a whole team in one query, keyed by
     * "canonicalName|realm". mb-safe canonicalisation via BlizzardIdentity::name.
     *
     * @param  array<int, array{name: string, realm: string}>  $team
     * @return array<string, int>
     */
    private function resolveCharacterIds(array $team, string $region): array
    {
        if ($team === []) {
            return [];
        }

        $names = array_values(array_unique(array_map(fn ($m) => BlizzardIdentity::name($m['name']), $team)));
        $realms = array_values(array_unique(array_map(fn ($m) => $m['realm'], $team)));

        return Character::query()
            ->where('region', $region)
            ->where('game_version', 'retail')
            ->whereIn('name', $names)
            ->whereIn('realm', $realms)
            ->get(['id', 'name', 'realm'])
            ->mapWithKeys(fn ($c) => ["{$c->name}|{$c->realm}" => $c->id])
            ->all();
    }

    /**
     * @param  array{name: string, realm: string, realm_name?: ?string, specialization_id?: ?int, specialization: ?string, equipped_item_level: ?int}  $member
     */
    public function upsertMember(DungeonRun $run, array $member, string $region): void
    {
        $resolvedId = Character::query()
            ->where('name', BlizzardIdentity::name($member['name']))
            ->where('realm', $member['realm'])
            ->where('region', $region)
            ->where('game_version', 'retail')
            ->value('id');

        DB::table('dungeon_run_members')->updateOrInsert(
            [
                'dungeon_run_id' => $run->id,
                'character_name' => $member['name'],
                'character_realm' => $member['realm'],
                'character_region' => $region,
            ],
            [
                'character_id' => $resolvedId,
                'display_realm' => $member['realm_name'] ?? null,
                'spec_id' => $member['specialization_id'] ?? null,
                'spec_name' => $member['specialization'],
                'equipped_item_level' => $member['equipped_item_level'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Whether this player already occupies a seat on the run under any casing.
     * character_name is stored display-cased while the queried-character path
     * writes a lowercase name; a case-sensitive comparison would miss the match
     * and let a duplicate row slip past the unique index. mb-safe, in PHP so it
     * is DB-collation-independent (runs hold ≤5 members). (P1.3)
     */
    public function hasMember(DungeonRun $run, string $name, string $realm, string $region): bool
    {
        $canonical = BlizzardIdentity::name($name);

        return DB::table('dungeon_run_members')
            ->where('dungeon_run_id', $run->id)
            ->where('character_realm', $realm)
            ->where('character_region', $region)
            ->pluck('character_name')
            ->contains(fn ($existing) => BlizzardIdentity::name($existing) === $canonical);
    }
}
