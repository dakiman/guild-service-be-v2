<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Jobs\SyncGuildData;
use App\Models\Guild;
use Illuminate\Database\Eloquent\Collection;

class GuildService
{
    public function getByIdentity(string $region, string $realm, string $name): ?Guild
    {
        $guild = Guild::byIdentity($name, $realm, $region)->first();

        if (! $guild) {
            return null;
        }

        $guild->increment('num_of_searches');
        $guild->update(['last_searched_at' => now()]);

        if ($guild->isStale() || $guild->isRosterStale()) {
            SyncGuildData::dispatch($region, $realm, $name);
        }

        return $guild;
    }

    /**
     * @return array{recently_searched: Collection, most_popular: Collection}
     */
    public function getPopular(): array
    {
        return [
            'recently_searched' => Guild::recentlySearched(5)->get(),
            'most_popular' => Guild::mostPopular(5)->get(),
        ];
    }
}
