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
            'data' => [
                'id',
                'name',
                'realm',
                'region',
                'faction',
                'member_count',
                'achievement_points',
            ],
        ]);

        $this->assertSame('eu', $response->json('data.region'));
    }
}
