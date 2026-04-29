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
                'stats',
                'mythic_plus_rating',
                'pvp_brackets',
                'professions',
                'raid_progress',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids', 'stats'],
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

        $stats = $response->json('data.stats');
        if ($stats !== null) {
            $this->assertIsArray($stats, 'stats should be an associative array when present');
            $this->assertArrayHasKey('health', $stats, 'stats payload missing health');
            $this->assertArrayNotHasKey('_links', $stats, 'stats payload should have _links envelope stripped');
            $this->assertArrayNotHasKey('character', $stats, 'stats payload should have character envelope stripped');
        }

        $talents = $response->json('data.talents');
        $this->assertIsArray($talents);
        $this->assertArrayHasKey('class', $talents);
        $this->assertArrayHasKey('spec', $talents);
        $this->assertArrayHasKey('hero', $talents);
        $this->assertArrayHasKey('pvp', $talents);

        $dungeonRuns = $response->json('data.dungeon_runs');
        $this->assertIsArray($dungeonRuns);

        if (! empty($dungeonRuns)) {
            foreach ($dungeonRuns as $i => $run) {
                $this->assertArrayHasKey('members', $run, "dungeon_runs[{$i}] missing members — controller must eager-load dungeonRuns.members");
                $this->assertIsArray($run['members'], "dungeon_runs[{$i}].members must be an array");

                foreach ($run['members'] as $j => $member) {
                    $this->assertArrayHasKey('character_id', $member, "dungeon_runs[{$i}].members[{$j}] missing character_id");
                    $this->assertArrayHasKey('character_name', $member, "dungeon_runs[{$i}].members[{$j}] missing character_name");
                    $this->assertArrayHasKey('character_realm', $member, "dungeon_runs[{$i}].members[{$j}] missing character_realm");
                    $this->assertArrayHasKey('character_region', $member, "dungeon_runs[{$i}].members[{$j}] missing character_region");
                    $this->assertArrayHasKey('spec_name', $member, "dungeon_runs[{$i}].members[{$j}] missing spec_name");
                    $this->assertArrayHasKey('equipped_item_level', $member, "dungeon_runs[{$i}].members[{$j}] missing equipped_item_level");
                }
            }
        }
    }

    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_populates_slice_data(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
        $this->getJson($url);                         // cold call triggers Full sync inline (queue=sync)
        $response = $this->getJson($url)->assertOk(); // second call returns populated data

        match ($slot) {
            'geared_main' => $this->assertMythicPlusRatingShape($response),
            'pvp_player' => $this->assertPvpBracketsShape($response),
            'profession_rich' => $this->assertProfessionsShape($response),
            'raider' => $this->assertRaidProgressShape($response),
        };
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

    private function assertMythicPlusRatingShape($r): void
    {
        $x = $r->json('data.mythic_plus_rating');
        if ($x === null) {
            $this->markTestSkipped('fixture has no current_mythic_rating right now');
        }
        $this->assertIsInt($x['rating']);
        $this->assertArrayHasKey('color', $x);
        $this->assertIsArray($x['per_spec']);
    }

    private function assertPvpBracketsShape($r): void
    {
        $b = $r->json('data.pvp_brackets');
        $this->assertIsArray($b);
        if ($b === []) {
            $this->markTestSkipped('fixture has no pvp brackets right now');
        }
        foreach ($b as $row) {
            $this->assertMatchesRegularExpression('/^(2v2|3v3|rbg|shuffle(-.+)?|blitz-[a-z-]+)$/', $row['bracket']);
            $this->assertArrayHasKey('season', $row);
            $this->assertArrayHasKey('weekly', $row);
        }
    }

    private function assertProfessionsShape($r): void
    {
        $p = $r->json('data.professions');
        $this->assertIsArray($p);
        if ($p === []) {
            $this->markTestSkipped('fixture has no professions right now');
        }
        foreach ($p as $row) {
            $this->assertArrayHasKey('profession_id', $row);
            $this->assertArrayHasKey('tier_name', $row);
            $this->assertIsBool($row['is_primary']);
        }
    }

    private function assertRaidProgressShape($r): void
    {
        $x = $r->json('data.raid_progress');
        $this->assertIsArray($x);
        if ($x === []) {
            $this->markTestSkipped('fixture has no raid progress right now');
        }
        $difficulties = array_unique(array_column($x, 'difficulty'));
        foreach ($difficulties as $d) {
            $this->assertContains($d, ['lfr', 'normal', 'heroic', 'mythic']);
        }
    }
}
