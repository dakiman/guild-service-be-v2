<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Models\Character;

class CharacterService
{
    public function getByIdentity(string $region, string $realm, string $name): ?Character
    {
        $character = Character::byIdentity($name, $realm, $region)->first();

        if (! $character) {
            return null;
        }

        $character->increment('num_of_searches');
        $character->update(['last_searched_at' => now()]);

        if ($character->isMythicsStale()) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full);
        } elseif ($character->isStale()) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard);
        }

        return $character;
    }

    /**
     * @return array{recently_searched: \Illuminate\Database\Eloquent\Collection, most_popular: \Illuminate\Database\Eloquent\Collection}
     */
    public function getPopular(): array
    {
        return [
            'recently_searched' => Character::recentlySearched(5)->get(),
            'most_popular' => Character::mostPopular(5)->get(),
        ];
    }

    public function toggleRecruitment(Character $character): Character
    {
        $character->recruitment = ! $character->recruitment;
        $character->save();

        return $character;
    }
}
