<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Exceptions\EntityNotFoundException;
use App\Models\Character;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CharacterService
{
    public function getByIdentity(string $region, string $realm, string $name, bool $forceRefresh = false): ?Character
    {
        $character = Character::byIdentity($name, $realm, $region)->first();

        if (! $character) {
            if (Cache::has("blizzard:not-found:character:{$region}:{$realm}:{$name}")) {
                throw new EntityNotFoundException;
            }

            return null;
        }

        $character->increment('num_of_searches', 1, ['last_searched_at' => now()]);

        $anySliceStale = $character->isMythicsStale()
            || $character->isPvpStale()
            || $character->isProfessionsStale()
            || $character->isRaidsStale()
            || $character->isStatsStale()
            || $character->isTitlesStale()
            || $character->isReputationsStale()
            || $character->isCollectionsStale()
            || $character->isAchievementsStale();

        // TODO(Plan 3): $forceRefresh must also bypass SyncCharacterData::$uniqueFor
        //               (nonced uniqueId) or back-to-back dispatches get dedup'd.
        if ($forceRefresh || $anySliceStale) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full);
        } elseif ($character->isStale()) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard);
        }

        return $character;
    }

    /**
     * @return array{recently_searched: Collection, most_popular: Collection}
     */
    public function getPopular(): array
    {
        return Cache::remember('characters:popular', 30, fn () => [
            'recently_searched' => Character::recentlySearched(5)->get(),
            'most_popular' => Character::mostPopular(5)->get(),
        ]);
    }

    public function toggleRecruitment(Character $character): Character
    {
        $character->recruitment = ! $character->recruitment;
        $character->save();

        return $character;
    }
}
