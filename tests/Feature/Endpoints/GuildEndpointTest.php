<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class GuildEndpointTest extends EndpointIntegrationTestCase
{
    /**
     * @return array<int, array{0: array{region: string, realm: string, name: string}}>
     */
    public static function guildProvider(): array
    {
        return array_map(fn ($g) => [$g], self::GUILDS);
    }

    #[DataProvider('guildProvider')]
    public function test_guild_endpoint_returns_valid_response(array $fixture): void
    {
        $this->requireFixture($fixture, 'guild');

        $url = "/api/v1/guilds/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";

        // Cold cache may 202, second call returns 200 (queue runs sync in tests).
        $this->getJson($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $response->assertJsonStructure([
            'guild' => [
                'id',
                'name',
                'realm',
                'region',
                'faction',
                'member_count',
                'achievement_points',
            ],
        ]);

        $this->assertSame($fixture['region'], $response->json('guild.region'));
    }

    #[DataProvider('guildProvider')]
    public function test_guild_endpoint_returns_enriched_member_rows(array $fixture): void
    {
        $this->requireFixture($fixture, 'guild');

        $url = "/api/v1/guilds/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";

        // Cold cache may 202, second call returns 200.
        $this->getJson($url);
        $response = $this->getJson($url);

        $response->assertOk();

        $members = $response->json('members.data');
        $this->assertIsArray($members);
        $this->assertNotEmpty($members, 'guild has at least one member');

        $row = $members[0];
        foreach ([
            'id', 'guild_id', 'name', 'realm', 'level', 'class_id', 'race_id', 'rank',
            'faction', 'equipped_item_level', 'mythic_plus_rating',
            'active_specialization_id', 'synced_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "row missing key: {$key}");
        }

        $this->assertContains($row['faction'], ['Alliance', 'Horde', null]);
    }
}
