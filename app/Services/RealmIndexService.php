<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealmIndexService
{
    public function __construct(
        private readonly TokenManagerInterface $tokenManager,
    ) {}

    /**
     * Aggregate realm indexes from all configured regions into a flat list.
     * Each entry: {slug, name, region}. Display name is slug-derived
     * ("the-maelstrom" → "The Maelstrom") so KR/TW realms render with their
     * romanized names without us managing per-region locales.
     *
     * Per-region calls are individually cached inside BlizzardGameDataClient,
     * so a cold call hits Blizzard 4× then warm calls are pure cache reads.
     *
     * One region failing (auth, network, 5xx) does NOT abort the response —
     * we log and skip so the autocomplete keeps working with whatever
     * regions did succeed.
     */
    public function aggregate(): array
    {
        $regions = (array) config('blizzard.regions', ['eu', 'us', 'kr', 'tw']);
        $realms = [];

        foreach ($regions as $region) {
            try {
                $client = new BlizzardGameDataClient($this->tokenManager, $region);
                $index = $client->getRealmIndex();
            } catch (Throwable $e) {
                Log::warning('Failed to fetch realm index', [
                    'region' => $region,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($index['realms'] ?? [] as $entry) {
                $slug = $entry['slug'] ?? null;

                if (! is_string($slug) || $slug === '') {
                    continue;
                }

                $realms[] = [
                    'slug' => $slug,
                    'name' => $this->slugToName($slug),
                    'region' => $region,
                ];
            }
        }

        usort(
            $realms,
            fn (array $a, array $b) => strcmp($a['region'], $b['region']) ?: strcmp($a['name'], $b['name'])
        );

        return $realms;
    }

    private function slugToName(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }
}
