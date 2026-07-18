<?php

declare(strict_types=1);

namespace App\Services;

use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Exceptions\EntityNotFoundException;
use App\Http\Resources\CharacterSummaryResource;
use App\Models\Character;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CharacterService
{
    public function getByIdentity(string $region, string $realm, string $name, bool $forceRefresh = false): ?Character
    {
        if ($forceRefresh) {
            // A force-refresh recovers from a stale "not found" verdict too —
            // Blizzard 404s are re-tried, not permanently trusted, once the
            // user explicitly asks for fresh data.
            Cache::forget("blizzard:not-found:character:{$region}:{$realm}:{$name}");
        }

        $character = Character::byIdentity($name, $realm, $region)->first();

        if (! $character) {
            if (! $forceRefresh && Cache::has("blizzard:not-found:character:{$region}:{$realm}:{$name}")) {
                throw new EntityNotFoundException;
            }

            return null;
        }

        // withoutTimestamps: a search must not touch `updated_at` — that column
        // is the profile-sync clock `isStale()` reads. (P1.1)
        Character::withoutTimestamps(
            fn () => $character->increment('num_of_searches', 1, ['last_searched_at' => now()]),
        );

        // Sub-endgame: Standard is the ceiling. Slice staleness is skipped —
        // those timestamps are null by design and would read stale forever.
        if (! $character->isEndgame()) {
            if ($forceRefresh) {
                SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard, refreshNonce: Str::random(8));
            } elseif ($character->isStale()) {
                SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard);
            }

            return $character;
        }

        $anySliceStale = $character->isMythicsStale()
            || $character->isPvpStale()
            || $character->isProfessionsStale()
            || $character->isRaidsStale()
            || $character->isStatsStale()
            || $character->isTitlesStale()
            || $character->isReputationsStale()
            || $character->isCollectionsStale()
            || $character->isAchievementsStale();

        // StaleOnly (was Full): the job consults Character::is*Stale() at
        // execution time and only re-fetches slices that read stale then, so
        // an on-view dispatch never burns a full 9-slice fan-out for a single
        // stale slice. $forceRefresh escalates to a nonced Full sync — the
        // nonce bypasses ShouldBeUnique so a `?refresh=1` immediately after a
        // regular dispatch isn't silently deduped away.
        if ($forceRefresh) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Full, refreshNonce: Str::random(8));
        } elseif ($anySliceStale) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::StaleOnly);
        } elseif ($character->isStale()) {
            SyncCharacterData::dispatch($region, $realm, $name, SyncDepth::Standard);
        }

        return $character;
    }

    /**
     * Cached values must be plain arrays: cache.serializable_classes is false,
     * so objects (e.g. Eloquent Collections) come back from Redis as
     * __PHP_Incomplete_Class and 500 every cache-hit request.
     *
     * @return array{recently_searched: array, most_popular: array}
     */
    public function getPopular(): array
    {
        return Cache::remember('characters:popular', 30, fn () => [
            'recently_searched' => $this->summarize(Character::recentlySearched(5)->get()),
            'most_popular' => $this->summarize(Character::mostPopular(5)->get()),
        ]);
    }

    private function summarize(Collection $characters): array
    {
        $request = request();

        return $characters->map(fn (Character $c) => (new CharacterSummaryResource($c))->toArray($request))->all();
    }

    public function toggleRecruitment(Character $character): Character
    {
        $character->recruitment = ! $character->recruitment;
        $character->save();

        return $character;
    }
}
