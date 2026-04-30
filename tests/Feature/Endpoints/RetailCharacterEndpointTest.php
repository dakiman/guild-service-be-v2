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
                'titles',
                'reputations',
                'mounts',
                'pets',
                'toys',
                'achievements',
            ],
            'meta' => [
                'game_version',
                'forced_refresh',
                'freshness' => ['profile', 'mythic_plus', 'pvp', 'professions', 'raids', 'stats', 'titles', 'reputations', 'collections', 'achievements'],
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
            'rep_grinder' => $this->assertReputationsShape($response),
        };
    }

    /**
     * Reputations only populates when BLIZZARD_SYNC_REPUTATIONS_ENABLED=true.
     * Skip cleanly when the flag is off so the test passes in default env.
     */
    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_includes_reputations_when_flag_enabled(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        if (! config('blizzard.sync.reputations_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_REPUTATIONS_ENABLED is false; populated-reputations assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $reputations = $response->json('data.reputations');
        $this->assertIsArray($reputations);

        if ($slot === 'rep_grinder') {
            $this->assertNotEmpty(
                $reputations,
                'rep_grinder fixture should expose at least one reputation entry; set BLIZZARD_SYNC_REPUTATIONS_ENABLED=true and re-run if empty.',
            );
        }

        foreach ($reputations as $i => $rep) {
            $this->assertArrayHasKey('faction_id', $rep, "reputations[{$i}] missing faction_id");
            $this->assertArrayHasKey('faction_name', $rep, "reputations[{$i}] missing faction_name");
            $this->assertArrayHasKey('standing', $rep, "reputations[{$i}] missing standing");
            $this->assertArrayHasKey('value', $rep, "reputations[{$i}] missing value");
            $this->assertArrayHasKey('max', $rep, "reputations[{$i}] missing max");
            $this->assertContains(
                $rep['standing'],
                ['hated', 'hostile', 'unfriendly', 'neutral', 'friendly', 'honored', 'revered', 'exalted'],
                "reputations[{$i}].standing has unexpected value '{$rep['standing']}'",
            );
        }

        $this->assertSame('fresh', $response->json('meta.freshness.reputations'), 'reputations freshness should be fresh after warm sync');
    }

    /**
     * Titles only populates when BLIZZARD_SYNC_TITLES_ENABLED=true.
     * Skip cleanly when the flag is off so the test passes in default env.
     */
    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_includes_titles_when_flag_enabled(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        if (! config('blizzard.sync.titles_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_TITLES_ENABLED is false; populated-titles assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $titles = $response->json('data.titles');
        $this->assertIsArray($titles);
        foreach ($titles as $i => $title) {
            $this->assertArrayHasKey('id', $title, "titles[{$i}] missing id");
            $this->assertArrayHasKey('name', $title, "titles[{$i}] missing name");
            $this->assertArrayHasKey('display_string', $title, "titles[{$i}] missing display_string");
            $this->assertArrayHasKey('is_selected', $title, "titles[{$i}] missing is_selected");
            $this->assertIsInt($title['id']);
            $this->assertIsString($title['name']);
            $this->assertIsString($title['display_string']);
            $this->assertIsBool($title['is_selected']);
        }

        // At most one title should be selected at a time.
        $selectedCount = count(array_filter($titles, fn ($t) => $t['is_selected'] === true));
        $this->assertLessThanOrEqual(1, $selectedCount, 'At most one title can be is_selected=true');

        $this->assertSame('fresh', $response->json('meta.freshness.titles'), 'titles freshness should be fresh after warm sync');
    }

    /**
     * Collections only populates when BLIZZARD_SYNC_COLLECTIONS_ENABLED=true.
     * Skip cleanly when the flag is off so the test passes in default env.
     */
    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_includes_collections_when_flag_enabled(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        if (! config('blizzard.sync.collections_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_COLLECTIONS_ENABLED is false; populated-collections assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $mounts = $response->json('data.mounts');
        $pets = $response->json('data.pets');
        $toys = $response->json('data.toys');

        $this->assertIsArray($mounts);
        $this->assertIsArray($pets);
        $this->assertIsArray($toys);

        foreach ($mounts as $i => $m) {
            $this->assertArrayHasKey('mount_id', $m, "mounts[{$i}] missing mount_id");
            $this->assertArrayHasKey('name', $m, "mounts[{$i}] missing name");
            $this->assertArrayHasKey('is_useable', $m, "mounts[{$i}] missing is_useable");
            $this->assertIsInt($m['mount_id']);
            $this->assertIsBool($m['is_useable']);
        }
        foreach ($pets as $i => $p) {
            foreach (['pet_id', 'species_id', 'name', 'level', 'is_favorite'] as $key) {
                $this->assertArrayHasKey($key, $p, "pets[{$i}] missing {$key}");
            }
            $this->assertIsBool($p['is_favorite']);
        }
        foreach ($toys as $i => $t) {
            $this->assertArrayHasKey('toy_id', $t, "toys[{$i}] missing toy_id");
            $this->assertArrayHasKey('name', $t, "toys[{$i}] missing name");
            $this->assertIsInt($t['toy_id']);
        }

        $this->assertSame('fresh', $response->json('meta.freshness.collections'), 'collections freshness should be fresh after warm sync');
    }

    /**
     * Achievements only populates when BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED=true.
     * Skip cleanly when the flag is off so the test passes in default env.
     */
    #[DataProvider('retailCharacterProvider')]
    public function test_retail_endpoint_includes_achievements_when_flag_enabled(array $fixture, string $slot): void
    {
        $this->requireFixture($fixture, $slot);

        if (! config('blizzard.sync.achievements_enabled')) {
            $this->markTestSkipped('BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED is false; populated-achievements assertion is gated.');
        }

        $url = "/api/v1/characters/{$fixture['region']}/{$fixture['realm']}/{$fixture['name']}";
        $this->warmCharacterOrSkip($url);

        $response = $this->getJson($url);
        $response->assertOk();

        $achievements = $response->json('data.achievements');
        $this->assertIsArray($achievements);
        $this->assertNotEmpty(
            $achievements,
            'achievements array should not be empty for a live character when the slice is enabled'
        );

        foreach ($achievements as $i => $row) {
            $this->assertArrayHasKey('achievement_id', $row, "achievements[{$i}] missing achievement_id");
            $this->assertArrayHasKey('completed_timestamp', $row, "achievements[{$i}] missing completed_timestamp");
            $this->assertIsInt($row['achievement_id']);
        }

        $this->assertSame('fresh', $response->json('meta.freshness.achievements'), 'achievements freshness should be fresh after warm sync');
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

    private function assertReputationsShape($r): void
    {
        $x = $r->json('data.reputations');
        $this->assertIsArray($x);
        if ($x === []) {
            $this->markTestSkipped('fixture has no reputations right now (flag off, or empty list)');
        }
        foreach ($x as $row) {
            $this->assertArrayHasKey('faction_id', $row);
            $this->assertIsInt($row['faction_id']);
            $this->assertArrayHasKey('standing', $row);
            $this->assertContains(
                $row['standing'],
                ['hated', 'hostile', 'unfriendly', 'neutral', 'friendly', 'honored', 'revered', 'exalted'],
            );
        }
    }

    public function test_reputation_response_includes_faction_block_with_expansion(): void
    {
        \App\Models\GameDataExpansion::firstOrCreate(['id' => 1], ['name' => 'The War Within', 'display_order' => 1]);
        \App\Models\GameDataFaction::firstOrCreate(['id' => 2570], ['name' => 'Council of Dornogal', 'expansion_id' => 1]);

        $now = now();
        $character = \App\Models\Character::factory()->create([
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
        \App\Models\CharacterReputation::create([
            'character_id' => $character->id,
            'faction_id' => 2570,
            'faction_name' => 'Council of Dornogal',
            'standing' => 'exalted',
            'value' => 21000,
            'max' => 0,
        ]);

        $url = "/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}";
        $response = $this->getJson($url)->assertOk();

        $this->assertSame(2570, $response->json('data.reputations.0.faction.id'));
        $this->assertSame('Council of Dornogal', $response->json('data.reputations.0.faction.name'));
        $this->assertSame(1, $response->json('data.reputations.0.faction.expansion.id'));
        $this->assertSame('The War Within', $response->json('data.reputations.0.faction.expansion.name'));
        $this->assertSame(1, $response->json('data.reputations.0.faction.expansion.display_order'));
    }

    public function test_reputation_response_includes_null_expansion_when_unmapped(): void
    {
        \App\Models\GameDataFaction::firstOrCreate(['id' => 99999], ['name' => 'Future Faction', 'expansion_id' => null]);

        $now = now();
        $character = \App\Models\Character::factory()->create([
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
        \App\Models\CharacterReputation::create([
            'character_id' => $character->id,
            'faction_id' => 99999,
            'faction_name' => 'Future Faction',
            'standing' => 'neutral',
            'value' => 0,
            'max' => 0,
        ]);

        $url = "/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}";
        $response = $this->getJson($url)->assertOk();

        $this->assertSame(99999, $response->json('data.reputations.0.faction.id'));
        $this->assertNull($response->json('data.reputations.0.faction.expansion'));
    }

    public function test_reputation_response_omits_faction_block_when_no_game_data_row(): void
    {
        $now = now();
        $character = \App\Models\Character::factory()->create([
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
        \App\Models\CharacterReputation::create([
            'character_id' => $character->id,
            'faction_id' => 88888,
            'faction_name' => 'Unknown Faction',
            'standing' => 'neutral',
            'value' => 0,
            'max' => 0,
        ]);

        $url = "/api/v1/characters/{$character->region}/{$character->realm}/{$character->name}";
        $response = $this->getJson($url)->assertOk();

        $this->assertSame(88888, $response->json('data.reputations.0.faction_id'));
        $response->assertJsonMissingPath('data.reputations.0.faction');
    }
}
