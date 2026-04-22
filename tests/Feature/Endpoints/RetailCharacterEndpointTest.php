<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class RetailCharacterEndpointTest extends EndpointIntegrationTestCase
{
    /**
     * @return array<string, array{0: array{region: string, realm: string, name: string}, 1: string}>
     */
    public static function retailCharacterProvider(): array
    {
        $out = [];
        foreach (self::RETAIL_CHARACTERS as $slot => $fixture) {
            $out[$slot] = [$fixture, $slot];
        }

        return $out;
    }

    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_returns_valid_response(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";

        // First call may 202 on cold cache; trigger sync and then poll warm.
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'realm',
                'region',
                'game_version',
                'level',
                'class_id',
                'race_id',
                'faction',
                'average_item_level',
                'equipped_item_level',
                'active_specialization',
                'talent_loadout_code',
                'media' => ['avatar', 'inset', 'main'],
                'talents' => ['class', 'spec', 'hero', 'pvp'],
                'equipment',
                'mythic_plus_rating',
                'pvp_brackets',
                'professions',
                'raid_progress',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids'],
            ],
        ]);

        $this->assertSame('retail', $response->json('data.game_version'));

        $equipment = $response->json('data.equipment');
        $this->assertIsArray($equipment);
        $this->assertNotEmpty($equipment, 'equipment array should not be empty for a geared character');

        foreach ($equipment as $i => $item) {
            $this->assertArrayHasKey('id', $item, "equipment[{$i}] missing id");
            $this->assertArrayHasKey('name', $item, "equipment[{$i}] missing name");
            $this->assertArrayHasKey('quality', $item, "equipment[{$i}] missing quality");
            $this->assertArrayHasKey('slot', $item, "equipment[{$i}] missing slot");
            $this->assertArrayHasKey('item_level', $item, "equipment[{$i}] missing item_level");
            $this->assertArrayHasKey('bonus', $item, "equipment[{$i}] missing bonus");
            $this->assertArrayHasKey('gems', $item, "equipment[{$i}] missing gems");
            $this->assertArrayHasKey('enchantments', $item, "equipment[{$i}] missing enchantments");
            $this->assertArrayHasKey('set_id', $item, "equipment[{$i}] missing set_id");
            $this->assertArrayHasKey('stats', $item, "equipment[{$i}] missing stats");
            $this->assertIsArray($item['bonus'], "equipment[{$i}].bonus not array");
            $this->assertIsArray($item['gems'], "equipment[{$i}].gems not array");
            $this->assertIsArray($item['enchantments'], "equipment[{$i}].enchantments not array");
            $this->assertIsArray($item['stats'], "equipment[{$i}].stats not array");
        }

        $talents = $response->json('data.talents');
        $this->assertIsArray($talents);
        $this->assertArrayHasKey('class', $talents);
        $this->assertArrayHasKey('spec', $talents);
        $this->assertArrayHasKey('hero', $talents);
        $this->assertArrayHasKey('pvp', $talents);
    }

    /**
     * Dispatch a sync job synchronously if the character isn't persisted yet,
     * so subsequent GETs return 200 instead of 202.
     */
    private function warmCharacterOrSkip(string $url): void
    {
        // A cold 202 means no data yet; wait for the queue to do the work.
        // Since tests run with queue=sync, the dispatch during the 202 handler
        // completes before the response returns — a second GET sees the row.
        $this->getJson($url);
    }
}
