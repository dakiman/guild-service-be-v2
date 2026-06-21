<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlizzardProfileClientTest extends TestCase
{
    private function makeClient(string $region = 'us'): BlizzardProfileClient
    {
        $tokenManager = new class implements TokenManagerInterface
        {
            public function getToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }

            public function refreshToken(string $region = 'eu'): string
            {
                return 'fake-token';
            }
        };

        return new BlizzardProfileClient($tokenManager, $region);
    }

    public function test_get_guild_data_normalizes_realm_and_name_in_url(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(['name' => 'attorney-at-law'], 200),
        ]);

        $this->makeClient('us')->getGuildData('Blades Edge', 'Attorney at Law');

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/data/wow/guild/blades-edge/attorney-at-law');
        });
    }

    public function test_get_guild_data_throws_blizzard_not_found_on_404(): void
    {
        Http::fake([
            'us.api.blizzard.com/data/wow/guild/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        $this->expectException(BlizzardNotFoundException::class);

        $this->makeClient('us')->getGuildData('illidan', 'liquid-disbanded');
    }

    public function test_get_character_data_throws_blizzard_not_found_on_basic_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
        ]);

        $this->expectException(BlizzardNotFoundException::class);

        $this->makeClient('eu')->getCharacterData('the-maelstrom', 'zzzzzznonexistent');
    }

    public function test_get_character_data_normalizes_realm_and_name_in_url(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*' => Http::response([
                'name' => 'Cirna', 'level' => 90, 'achievement_points' => 100,
                'average_item_level' => 240, 'equipped_item_level' => 240,
                'gender' => ['name' => 'Female'], 'faction' => ['name' => 'Alliance'],
                'race' => ['id' => 4], 'character_class' => ['id' => 5],
            ], 200),
        ]);

        $this->makeClient('eu')->getCharacterData('The Maelstrom', 'Cirna');

        Http::assertSent(function (Request $req) {
            $path = parse_url($req->url(), PHP_URL_PATH) ?? '';

            // The pool issues four sibling requests; the basic one ends with /cirna,
            // the others have /cirna/character-media etc. Any of them passing means the
            // realm and name segments were normalized.
            return str_contains($path, '/profile/wow/character/the-maelstrom/cirna');
        });
    }

    public function test_get_character_reputations_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/reputations*' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterReputations('the-maelstrom', 'cirna'));
    }

    public function test_get_character_reputations_returns_payload_on_200(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/reputations*' => Http::response([
                'reputations' => [
                    ['faction' => ['id' => 2510, 'name' => 'Valdrakken Accord'],
                        'standing' => ['raw' => 21000, 'max' => 21000, 'name' => 'Exalted']],
                ],
            ], 200),
        ]);

        $payload = $this->makeClient('eu')->getCharacterReputations('The Maelstrom', 'Cirna');

        $this->assertIsArray($payload);
        $this->assertSame(2510, $payload['reputations'][0]['faction']['id']);
    }

    // -------------------------------------------------------------------------
    // P0.2: 404 must resolve to null (not throw). request() uses retry(throw:true),
    // so the old `if ($response->status() === 404) return null;` guard was dead code —
    // a 404 reached the caller as a RequestException, never returning null.
    // -------------------------------------------------------------------------

    public function test_get_character_pvp_summary_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/pvp-summary*' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterPvpSummary('the-maelstrom', 'cirna'));
    }

    public function test_get_character_professions_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/professions*' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterProfessions('the-maelstrom', 'cirna'));
    }

    public function test_get_character_raid_encounters_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/encounters/raids*' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterRaidEncounters('the-maelstrom', 'cirna'));
    }

    public function test_get_character_stats_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/statistics*' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterStats('the-maelstrom', 'cirna'));
    }

    public function test_get_character_titles_returns_null_on_404(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/titles*' => Http::response(['code' => 404], 404),
        ]);

        $this->assertNull($this->makeClient('eu')->getCharacterTitles('the-maelstrom', 'cirna'));
    }

    public function test_get_character_stats_rethrows_non_404_client_error(): void
    {
        // Only 404 means "no data". A 403/other failure must still surface as an
        // exception so the slice records a failure instead of stamping success.
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/statistics*' => Http::response(['code' => 403], 403),
        ]);

        $this->expectException(RequestException::class);

        $this->makeClient('eu')->getCharacterStats('the-maelstrom', 'cirna');
    }

    // -------------------------------------------------------------------------
    // P0.3: Http::pool() slots conflate transient failures (5xx / connection
    // errors) with "no data". A 5xx must throw so the caller records a slice
    // failure instead of persisting empty data over real rows; only 404 → null.
    // -------------------------------------------------------------------------

    public function test_get_character_collections_throws_when_a_pool_slot_5xxs(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/collections/mounts*' => Http::response([], 500),
            'eu.api.blizzard.com/*' => Http::response(['pets' => [], 'toys' => []], 200),
        ]);

        $this->expectException(RequestException::class);

        $this->makeClient('eu')->getCharacterCollections('the-maelstrom', 'cirna');
    }

    public function test_get_character_collections_throws_on_pool_slot_connection_error(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/collections/mounts*' => fn () => throw new ConnectionException('connection refused'),
            'eu.api.blizzard.com/*' => Http::response(['pets' => [], 'toys' => []], 200),
        ]);

        $this->expectException(ConnectionException::class);

        $this->makeClient('eu')->getCharacterCollections('the-maelstrom', 'cirna');
    }

    public function test_get_character_mythic_plus_pool_throws_when_base_5xxs(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/mythic-keystone-profile/season/*' => Http::response(['best_runs' => []], 200),
            'eu.api.blizzard.com/profile/wow/character/*/mythic-keystone-profile*' => Http::response([], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->makeClient('eu')->getCharacterMythicPlusPool('the-maelstrom', 'cirna', 14);
    }

    public function test_get_character_pvp_brackets_throws_when_a_bracket_5xxs(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/pvp-bracket/*' => Http::response([], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->makeClient('eu')->getCharacterPvpBracketsChunked('the-maelstrom', 'cirna', ['2v2']);
    }

    public function test_get_character_data_throws_when_secondary_pool_slot_5xxs(): void
    {
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/equipment*' => Http::response([], 500),
            'eu.api.blizzard.com/*' => Http::response($this->characterBasicBody(), 200),
        ]);

        $this->expectException(RequestException::class);

        $this->makeClient('eu')->getCharacterData('The Maelstrom', 'Cirna');
    }

    public function test_get_character_data_returns_null_for_secondary_slot_404(): void
    {
        // A 404 on an optional slice (e.g. media) is legit "no data" — null,
        // not a thrown failure — while the basic profile still comes through.
        Http::fake([
            'eu.api.blizzard.com/profile/wow/character/*/character-media*' => Http::response(['code' => 404], 404),
            'eu.api.blizzard.com/*' => Http::response($this->characterBasicBody(), 200),
        ]);

        $result = $this->makeClient('eu')->getCharacterData('The Maelstrom', 'Cirna');

        $this->assertNull($result['media']);
        $this->assertIsArray($result['basic']);
        $this->assertSame('Cirna', $result['basic']['name']);
    }

    private function characterBasicBody(): array
    {
        return [
            'name' => 'Cirna', 'level' => 90, 'achievement_points' => 100,
            'average_item_level' => 240, 'equipped_item_level' => 240,
            'gender' => ['name' => 'Female'], 'faction' => ['name' => 'Alliance'],
            'race' => ['id' => 4], 'character_class' => ['id' => 5],
            'realm' => ['slug' => 'the-maelstrom', 'name' => 'The Maelstrom'],
        ];
    }
}
