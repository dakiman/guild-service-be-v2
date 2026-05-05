<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\Cache;

class CharacterStatsService
{
    public function getStats(): array
    {
        return Cache::remember('stats:characters', 600, fn () => $this->computeStats());
    }

    private function computeStats(): array
    {
        return [
            'total_characters' => Character::count(),
            'class_distribution' => $this->getClassDistribution(),
            'spec_distribution' => $this->getSpecDistribution(),
            'faction_distribution' => $this->getFactionDistribution(),
            'race_distribution' => $this->getRaceDistribution(),
            'top_performers' => $this->getTopPerformers(),
        ];
    }

    private function getClassDistribution(): array
    {
        return Character::query()
            ->selectRaw('class_id, COUNT(*) as count, ROUND(AVG(average_item_level), 1) as avg_ilvl, ROUND(AVG(mythic_plus_rating), 1) as avg_mythic_plus_rating')
            ->groupBy('class_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'class_id' => $row->class_id,
                'count' => (int) $row->count,
                'avg_ilvl' => (float) $row->avg_ilvl,
                'avg_mythic_plus_rating' => (float) $row->avg_mythic_plus_rating,
            ])
            ->all();
    }

    private function getSpecDistribution(): array
    {
        return Character::query()
            ->selectRaw('active_specialization_id as spec_id, class_id, COUNT(*) as count')
            ->whereNotNull('active_specialization_id')
            ->groupBy('active_specialization_id', 'class_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'spec_id' => (int) $row->spec_id,
                'class_id' => (int) $row->class_id,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function getFactionDistribution(): array
    {
        $counts = Character::query()
            ->selectRaw("faction, COUNT(*) as count")
            ->groupBy('faction')
            ->pluck('count', 'faction');

        return [
            'horde' => (int) ($counts['Horde'] ?? 0),
            'alliance' => (int) ($counts['Alliance'] ?? 0),
        ];
    }

    private function getRaceDistribution(): array
    {
        return Character::query()
            ->selectRaw('race_id, COUNT(*) as count')
            ->groupBy('race_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'race_id' => (int) $row->race_id,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function getTopPerformers(int $limit = 5): array
    {
        return [
            'mythic_plus' => $this->getTopBy('mythic_plus_rating', $limit),
            'item_level' => $this->getTopBy('average_item_level', $limit),
            'achievement_points' => $this->getTopBy('achievement_points', $limit),
        ];
    }

    private function getTopBy(string $column, int $limit): array
    {
        return Character::query()
            ->select(['name', 'realm', 'region', 'class_id', 'active_specialization_id', $column])
            ->whereNotNull($column)
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'realm' => $row->realm,
                'region' => $row->region,
                'class_id' => (int) $row->class_id,
                'spec_id' => $row->active_specialization_id ? (int) $row->active_specialization_id : null,
                'value' => (float) $row->$column,
            ])
            ->all();
    }
}
