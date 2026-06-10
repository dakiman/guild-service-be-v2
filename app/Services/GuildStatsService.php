<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\Guild;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GuildStatsService
{
    // Tanks
    // 73 = Prot Warrior, 66 = Prot Paladin, 250 = Blood DK, 268 = Brewmaster Monk,
    // 581 = Vengeance DH, 104 = Guardian Druid
    // Healers
    // 65 = Holy Paladin, 256 = Disc Priest, 257 = Holy Priest, 264 = Resto Shaman,
    // 270 = Mistweaver Monk, 105 = Resto Druid, 1468 = Preservation Evoker
    private const SPEC_ROLES = [
        73 => 'tank', 66 => 'tank', 250 => 'tank', 268 => 'tank', 581 => 'tank', 104 => 'tank',
        65 => 'healer', 256 => 'healer', 257 => 'healer', 264 => 'healer', 270 => 'healer', 105 => 'healer', 1468 => 'healer',
    ];

    public function getStats(Guild $guild): array
    {
        return Cache::remember("stats:guild:{$guild->id}", 600, function () use ($guild) {
            return $this->computeStats($guild);
        });
    }

    private function computeStats(Guild $guild): array
    {
        $memberCount = Character::where('guild_id', $guild->id)->count();

        $endgameMembers = Character::where('guild_id', $guild->id)->endgameActive();

        $averages = (clone $endgameMembers)->select([
            DB::raw('ROUND(AVG(average_item_level), 1) as avg_ilvl'),
            DB::raw('ROUND(AVG(mythic_plus_rating), 1) as avg_rating'),
        ])->first();

        $topMplus = (clone $endgameMembers)
            ->whereNotNull('mythic_plus_rating')
            ->where('mythic_plus_rating', '>', 0)
            ->orderByDesc('mythic_plus_rating')
            ->first();

        $roleCounts = (clone $endgameMembers)
            ->whereNotNull('active_specialization_id')
            ->pluck('active_specialization_id')
            ->countBy(fn (int $specId) => self::SPEC_ROLES[$specId] ?? 'dps');

        $bestKeys = $this->bestKeys($guild);

        return [
            'member_count' => $memberCount,
            'avg_item_level' => (float) ($averages->avg_ilvl ?? 0),
            'avg_mythic_plus_rating' => (float) ($averages->avg_rating ?? 0),
            'top_mythic_plus' => $topMplus ? [
                'rating' => (int) $topMplus->mythic_plus_rating,
                'character' => [
                    'name' => $topMplus->name,
                    'realm' => $topMplus->realm,
                    'region' => $topMplus->region,
                    'class_id' => $topMplus->class_id,
                ],
            ] : null,
            'role_coverage' => [
                'tank' => $roleCounts->get('tank', 0),
                'healer' => $roleCounts->get('healer', 0),
                'dps' => $roleCounts->get('dps', 0),
            ],
            'best_keys' => $bestKeys,
        ];
    }

    /**
     * Highest timed key per dungeon from runs that have at least one guild member participating.
     */
    private function bestKeys(Guild $guild): array
    {
        $guildCharacterIds = Character::where('guild_id', $guild->id)->pluck('id');

        if ($guildCharacterIds->isEmpty()) {
            return [];
        }

        return DungeonRun::query()
            ->select('dungeon_id', 'dungeon_name', DB::raw('MAX(keystone_level) as key_level'))
            ->whereHas('memberEntries', fn ($q) => $q->whereIn('character_id', $guildCharacterIds))
            ->where('is_completed_on_time', true)
            ->groupBy('dungeon_id', 'dungeon_name')
            ->orderByDesc('key_level')
            ->get()
            ->map(fn ($row) => [
                'dungeon_id' => $row->dungeon_id,
                'dungeon_name' => $row->dungeon_name,
                'key_level' => (int) $row->key_level,
            ])
            ->values()
            ->all();
    }
}
