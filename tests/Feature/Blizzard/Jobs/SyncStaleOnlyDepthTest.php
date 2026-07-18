<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Enums\SyncDepth;
use App\Enums\SyncOrigin;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SyncDepth::StaleOnly — the job consults Character::is*Stale() AT EXECUTION
 * TIME and syncs only the slices that read stale, on top of the standard
 * body. No slice-set is ever serialized onto the job.
 */
class SyncStaleOnlyDepthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TokenManagerInterface::class, fn () => new class implements TokenManagerInterface
        {
            public function getToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }

            public function refreshToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }
        });

        // Heavy/flagged slices stay off — irrelevant to the staleness gating
        // under test, and keeps the fixture-response shape minimal.
        Config::set('blizzard.sync.mounts_enabled', false);
        Config::set('blizzard.sync.toys_enabled', false);
        Config::set('blizzard.sync.pets_enabled', false);
        Config::set('blizzard.sync.achievements_enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function seasonIndexFake(): array
    {
        // game_data_seasons registry is empty in these tests (not seeded), so
        // BlizzardGameDataClient::getCurrentMythicPlusSeason() falls back to
        // the live Blizzard season index — fake that endpoint explicitly
        // rather than depend on registry seeding.
        return [
            'eu.api.blizzard.com/data/wow/mythic-keystone/season/index*' => Http::response([
                'seasons' => [['id' => 17]],
                'current_season' => ['id' => 17],
            ], 200),
        ];
    }

    public function test_stale_only_syncs_only_the_one_stale_slice(): void
    {
        // startOfSecond: DB round-trip drops sub-second precision, so an
        // equalTo() comparison against a microsecond-precise $now would be
        // spuriously false even when the column genuinely wasn't touched.
        $now = now()->startOfSecond();
        $character = Character::factory()->create([
            'name' => 'staleslice',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'level' => 90,
            'mythics_synced_at' => $now->clone()->subDays(30),
            'pvp_synced_at' => $now,
            'professions_synced_at' => $now,
            'raids_synced_at' => $now,
            'stats_synced_at' => $now,
            'titles_synced_at' => $now,
            'reputations_synced_at' => $now,
            'collections_synced_at' => $now,
            'achievements_synced_at' => $now,
        ]);

        Http::fake($this->seasonIndexFake() + [
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'staleslice', SyncDepth::StaleOnly);

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/mythic-keystone-profile/season/'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/pvp-summary'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/professions'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/encounters/raids'));

        $character->refresh();
        $this->assertNotNull($character->mythics_synced_at);
        $this->assertTrue(
            $character->mythics_synced_at->greaterThanOrEqualTo($now),
            'mythics_synced_at must advance after the stale slice syncs',
        );
        $this->assertTrue(
            $character->pvp_synced_at->equalTo($now),
            'pvp_synced_at must stay untouched — pvp was fresh, so StaleOnly must not touch it',
        );
    }

    public function test_stale_only_syncs_all_enabled_slices_when_all_null(): void
    {
        Http::fake($this->seasonIndexFake() + [
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'nevabout', SyncDepth::StaleOnly);

        // Never-synced slices read null -> stale -> StaleOnly ~ Full on first sync.
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/mythic-keystone-profile/season/'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/pvp-summary'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/professions'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/encounters/raids'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/statistics'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/titles'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/reputations'));

        $character = Character::where('name', 'nevabout')->where('realm', 'tarren-mill')->first();
        $this->assertNotNull($character);
        $this->assertNotNull($character->mythics_synced_at);
        $this->assertNotNull($character->pvp_synced_at);
        $this->assertNotNull($character->professions_synced_at);
        $this->assertNotNull($character->raids_synced_at);
        $this->assertNotNull($character->stats_synced_at);
        $this->assertNotNull($character->titles_synced_at);
        $this->assertNotNull($character->reputations_synced_at);
        // Collections/achievements have no upstream slice flag enabled here —
        // they still stamp synced_at (mirrors the disabled-flag contract).
        $this->assertNotNull($character->collections_synced_at);
        $this->assertNotNull($character->achievements_synced_at);
    }

    public function test_stale_only_never_dispatches_teammate_crawl(): void
    {
        Config::set('blizzard.sync.teammate_crawl_enabled', true);

        Bus::fake([SyncCharacterData::class]);

        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(), 200),
        ]);

        $now = now();
        Character::factory()->create([
            'name' => 'crawlguard',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'level' => 90,
            'mythics_synced_at' => $now,
            'pvp_synced_at' => $now,
            'professions_synced_at' => $now,
            'raids_synced_at' => $now,
            'stats_synced_at' => $now,
            'titles_synced_at' => $now,
            'reputations_synced_at' => $now,
            'collections_synced_at' => $now,
            'achievements_synced_at' => $now,
        ]);

        $job = new SyncCharacterData(
            region: 'eu',
            realm: 'tarren-mill',
            name: 'crawlguard',
            depth: SyncDepth::StaleOnly,
            origin: SyncOrigin::UserLookup,
        );

        app()->call([$job, 'handle']);

        // Crawl stays Full-only, whatever the global kill-switch says. StaleOnly
        // is the economy path — no recursive fan-out.
        Bus::assertNotDispatched(SyncCharacterData::class);
    }

    public function test_stale_only_skips_all_slices_for_sub_endgame_character(): void
    {
        Http::fake([
            'eu.api.blizzard.com/*' => Http::response($this->minimalCharacterPoolResponse(level: 45), 200),
        ]);

        SyncCharacterData::dispatchSync('eu', 'tarren-mill', 'lowbie', SyncDepth::StaleOnly);

        $character = Character::where('name', 'lowbie')->where('realm', 'tarren-mill')->first();
        $this->assertNotNull($character);
        $this->assertSame(45, $character->level);

        foreach ([
            'mythics_synced_at', 'pvp_synced_at', 'professions_synced_at', 'raids_synced_at',
            'stats_synced_at', 'titles_synced_at', 'reputations_synced_at',
            'collections_synced_at', 'achievements_synced_at',
        ] as $field) {
            $this->assertNull($character->{$field}, "{$field} must stay null — sub-endgame never gets slice fan-out");
        }

        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/mythic-keystone-profile/season/'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/pvp-summary'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/professions'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/encounters/raids'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/statistics'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/titles'));
        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/reputations'));
    }

    private function minimalCharacterPoolResponse(int $level = 90): array
    {
        return [
            'id' => 1,
            'name' => 'Testchar',
            'gender' => ['type' => 'MALE', 'name' => 'Male'],
            'faction' => ['type' => 'ALLIANCE', 'name' => 'Alliance'],
            'race' => ['id' => 1, 'name' => 'Human'],
            'character_class' => ['id' => 1, 'name' => 'Warrior'],
            'level' => $level,
            'achievement_points' => 100,
            'average_item_level' => 500,
            'equipped_item_level' => 490,
            'realm' => ['slug' => 'tarren-mill', 'name' => 'Tarren Mill'],
        ];
    }
}
