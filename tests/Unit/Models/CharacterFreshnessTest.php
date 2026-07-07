<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CharacterFreshnessTest extends TestCase
{
    /** All synced-at timestamps except updated_at. */
    private const SLICE_TIMESTAMPS = [
        'mythics_synced_at',
        'pvp_synced_at',
        'professions_synced_at',
        'raids_synced_at',
        'stats_synced_at',
        'titles_synced_at',
        'reputations_synced_at',
        'collections_synced_at',
        'achievements_synced_at',
    ];

    /**
     * In-memory (unsaved) character with every freshness timestamp set to now.
     * freshness() reads attributes only — no DB required.
     */
    private function freshCharacter(): Character
    {
        $c = new Character;
        $c->level = (int) config('blizzard.endgame_level', 90);
        $c->updated_at = now();
        foreach (self::SLICE_TIMESTAMPS as $f) {
            $c->{$f} = now();
        }

        return $c;
    }

    public function test_is_endgame_boundaries(): void
    {
        Config::set('blizzard.endgame_level', 90);

        $c = new Character;

        $c->level = 89;
        $this->assertFalse($c->isEndgame());

        $c->level = 90;
        $this->assertTrue($c->isEndgame());

        // >= so a level-cap raise can't silently demote everyone.
        $c->level = 91;
        $this->assertTrue($c->isEndgame());

        // Shell rows without a synced level are not endgame.
        $c->level = null;
        $this->assertFalse($c->isEndgame());
    }

    public function test_submax_freshness_is_profile_only(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $c = $this->freshCharacter();
        $c->level = 89;
        foreach (self::SLICE_TIMESTAMPS as $f) {
            $c->{$f} = null;
        }

        $this->assertSame(['profile'], array_keys($c->freshness()));
        $this->assertFalse($c->isNeverSynced());
    }

    public function test_all_synced_and_fresh_is_not_never_synced(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $c = $this->freshCharacter();

        $this->assertFalse($c->isNeverSynced());
        foreach ($c->freshness() as $slice => $state) {
            $this->assertSame('fresh', $state, "slice {$slice} should be fresh");
        }
    }

    public function test_null_mythics_timestamp_is_never_synced(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $c = $this->freshCharacter();
        $c->mythics_synced_at = null;

        $this->assertTrue($c->isNeverSynced());
        $this->assertSame('never_synced', $c->freshness()['mythic_plus']);
    }

    public function test_achievements_flag_off_ignores_null_achievements(): void
    {
        Config::set('blizzard.sync.achievements_enabled', false);

        $c = $this->freshCharacter();
        $c->achievements_synced_at = null;

        // The achievements key must be dropped wholesale — a null achievements
        // timestamp does not mark the character never_synced when the flag is off.
        $this->assertArrayNotHasKey('achievements', $c->freshness());
        $this->assertFalse($c->isNeverSynced());
    }

    public function test_achievements_flag_on_null_achievements_is_never_synced(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $c = $this->freshCharacter();
        $c->achievements_synced_at = null;

        $this->assertSame('never_synced', $c->freshness()['achievements']);
        $this->assertTrue($c->isNeverSynced());
    }

    public function test_resource_meta_freshness_matches_model(): void
    {
        Config::set('blizzard.sync.achievements_enabled', true);

        $c = $this->freshCharacter();
        $meta = (new CharacterResource($c))->with(new Request);

        $this->assertSame($c->freshness(), $meta['meta']['freshness']);
    }
}
