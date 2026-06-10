<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use App\Models\DungeonRun;
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

        foreach ($team as $member) {
            $resolvedId = Character::query()
                ->where('name', strtolower($member['name']))
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
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $keep[] = [
                'name' => $member['name'],
                'realm' => $member['realm'],
                'region' => $region,
            ];
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
     * @param  array{name: string, realm: string, realm_name?: ?string, specialization_id?: ?int, specialization: ?string, equipped_item_level: ?int}  $member
     */
    public function upsertMember(DungeonRun $run, array $member, string $region): void
    {
        $resolvedId = Character::query()
            ->where('name', strtolower($member['name']))
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
}
