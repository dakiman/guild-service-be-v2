<?php

declare(strict_types=1);

namespace App\Blizzard\Mappers;

use App\Support\KeystoneTimers;
use App\Support\SpecRoles;

class BlizzardLadderMapper
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<array{upgrade_level: int, qualifying_duration: int}>|null  $keystoneUpgrades
     * @return list<array{run: array<string,mixed>, members: list<array<string,mixed>>}>
     */
    public function mapLeaderboard(?array $payload, int $periodId, string $region, int $dungeonId, ?array $keystoneUpgrades): array
    {
        $groups = $payload['leading_groups'] ?? [];
        if (! is_array($groups) || $groups === []) {
            return [];
        }

        $timerMs = KeystoneTimers::plusOne($keystoneUpgrades);
        $affixes = array_values(array_filter(array_map(
            fn ($a) => $a['keystone_affix']['id'] ?? null,
            $payload['keystone_affixes'] ?? [],
        )));

        $out = [];
        foreach ($groups as $group) {
            $duration = (int) ($group['duration'] ?? 0);
            $completed = (int) ($group['completed_timestamp'] ?? 0);
            $level = (int) ($group['keystone_level'] ?? 0);
            if ($duration <= 0 || $completed <= 0 || $level <= 0) {
                continue;
            }

            $members = array_map(fn (array $m): array => [
                'profile_id' => $m['profile']['id'] ?? null,
                'name' => (string) ($m['profile']['name'] ?? ''),
                'realm_id' => $m['profile']['realm']['id'] ?? null,
                'realm_slug' => $m['profile']['realm']['slug'] ?? null,
                'faction' => $m['faction']['type'] ?? null,
                'spec_id' => $m['specialization']['id'] ?? null,
            ], array_values($group['members'] ?? []));

            $out[] = [
                'run' => [
                    'period_id' => $periodId,
                    'region' => $region,
                    'dungeon_id' => $dungeonId,
                    'keystone_level' => $level,
                    'duration' => $duration,
                    'completed_timestamp' => $completed,
                    'is_completed_on_time' => $timerMs !== null && $duration <= $timerMs,
                    'affixes' => $affixes,
                    'comp_signature' => $this->compSignature($members),
                    'run_hash' => $this->runHash($dungeonId, $completed, $duration, $members),
                ],
                'members' => $members,
            ];
        }

        return $out;
    }

    /**
     * Stable across the up-to-5 connected-realm ladders the same run appears on.
     *
     * @param  list<array<string, mixed>>  $members
     */
    public function runHash(int $dungeonId, int $completedTimestamp, int $duration, array $members): string
    {
        $ids = array_map(
            fn (array $m): string => isset($m['profile_id']) && $m['profile_id'] !== null
                ? (string) $m['profile_id']
                : mb_strtolower(($m['name'] ?? '').'-'.($m['realm_slug'] ?? $m['realm_id'] ?? '')),
            $members,
        );
        sort($ids, SORT_STRING);

        return sha1($dungeonId.'|'.$completedTimestamp.'|'.$duration.'|'.implode(',', $ids));
    }

    /**
     * "tank:healer:dps1,dps2,dps3" (spec ids, dps sorted) — null for anything
     * that isn't exactly 1/1/3 with all specs known.
     *
     * @param  list<array<string, mixed>>  $members
     */
    public function compSignature(array $members): ?string
    {
        if (count($members) !== 5) {
            return null;
        }

        $tank = [];
        $healer = [];
        $dps = [];
        foreach ($members as $m) {
            $specId = $m['spec_id'] ?? null;
            match (SpecRoles::roleFor($specId)) {
                'tank' => $tank[] = $specId,
                'healer' => $healer[] = $specId,
                'dps' => $dps[] = $specId,
                default => $unknown = true,
            };
        }
        if (isset($unknown) || count($tank) !== 1 || count($healer) !== 1 || count($dps) !== 3) {
            return null;
        }
        sort($dps);

        return $tank[0].':'.$healer[0].':'.implode(',', $dps);
    }
}
