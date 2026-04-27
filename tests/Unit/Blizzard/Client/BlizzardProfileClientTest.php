<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Client;

use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Exceptions\BlizzardNotFoundException;
use Illuminate\Http\Client\Request;
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
}
