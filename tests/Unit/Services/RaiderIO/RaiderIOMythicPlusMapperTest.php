<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\RaiderIORun;
use App\Services\RaiderIO\Mappers\RaiderIOMythicPlusMapper;
use Carbon\Carbon;
use Tests\TestCase;

class RaiderIOMythicPlusMapperTest extends TestCase
{
    private RaiderIOMythicPlusMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new RaiderIOMythicPlusMapper;
    }

    public function test_maps_character_profile_runs_and_deduplicates_across_lists(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);

        $this->assertCount(3, $runs);
        $this->assertContainsOnlyInstancesOf(RaiderIORun::class, $runs);

        $ids = array_map(fn (RaiderIORun $r) => $r->keystoneRunId, $runs);
        $this->assertCount(3, array_unique($ids));
    }

    public function test_maps_run_fields_correctly(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $seat = collect($runs)->first(fn (RaiderIORun $r) => $r->keystoneRunId === 21957615);

        $this->assertNotNull($seat);
        $this->assertSame(13, $seat->season);
        $this->assertSame(239, $seat->dungeonId);
        $this->assertSame('Seat of the Triumvirate', $seat->dungeonName);
        $this->assertSame(16, $seat->keystoneLevel);
        $this->assertSame(1814558, $seat->duration);
        $this->assertTrue($seat->isCompletedOnTime);
        $this->assertSame(429.2, $seat->score);
        $this->assertStringContainsString('21957615', $seat->url);
    }

    public function test_converts_completed_at_to_epoch_ms(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $seat = collect($runs)->first(fn (RaiderIORun $r) => $r->keystoneRunId === 21957615);

        $expected = Carbon::parse('2026-05-05T18:28:26.000Z')->getTimestampMs();
        $this->assertSame($expected, $seat->completedTimestamp);
    }

    public function test_maps_affixes(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $seat = collect($runs)->first(fn (RaiderIORun $r) => $r->keystoneRunId === 21957615);

        $this->assertSame([['id' => 9, 'name' => 'Tyrannical']], $seat->affixes);
    }

    public function test_depleted_run_sets_is_completed_on_time_false(): void
    {
        $data = [
            'mythic_plus_recent_runs' => [[
                'dungeon' => 'Test',
                'short_name' => 'TEST',
                'mythic_level' => 10,
                'completed_at' => '2026-05-01T12:00:00.000Z',
                'clear_time_ms' => 2000000,
                'par_time_ms' => 1800000,
                'num_keystone_upgrades' => 0,
                'map_challenge_mode_id' => 100,
                'score' => 100.0,
                'affixes' => [],
                'url' => 'https://raider.io/mythic-plus-runs/season-mn-1/99999-10-test',
            ]],
            'mythic_plus_best_runs' => [],
            'mythic_plus_highest_level_runs' => [],
        ];

        $runs = $this->mapper->mapCharacterProfileRuns($data, 13);

        $this->assertCount(1, $runs);
        $this->assertFalse($runs[0]->isCompletedOnTime);
    }

    public function test_returns_empty_array_for_missing_run_lists(): void
    {
        $runs = $this->mapper->mapCharacterProfileRuns([], 13);
        $this->assertSame([], $runs);
    }

    public function test_extracts_keystone_run_id_from_url(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/character-profile-mythicplus.json')),
            true,
        );

        $runs = $this->mapper->mapCharacterProfileRuns($fixture, 13);
        $ids = array_map(fn (RaiderIORun $r) => $r->keystoneRunId, $runs);

        $this->assertContains(21957615, $ids);
        $this->assertContains(21900000, $ids);
        $this->assertContains(21850000, $ids);
    }

    public function test_maps_run_details_roster(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/raiderio/run-details.json')),
            true,
        );

        $team = $this->mapper->mapRunDetailsRoster($fixture);

        $this->assertCount(5, $team);
        $this->assertSame('Testchar', $team[0]['name']);
        $this->assertSame('the-maelstrom', $team[0]['realm']);
        $this->assertSame('The Maelstrom', $team[0]['realm_name']);
        $this->assertSame(259, $team[0]['specialization_id']);
        $this->assertSame('Assassination', $team[0]['specialization']);
        $this->assertSame(489, $team[0]['equipped_item_level']);
    }

    public function test_run_details_roster_handles_missing_items(): void
    {
        $data = [
            'roster' => [[
                'character' => [
                    'name' => 'NoItems',
                    'realm' => ['slug' => 'kazzak', 'name' => 'Kazzak'],
                    'region' => ['slug' => 'eu'],
                    'spec' => ['id' => 73, 'name' => 'Protection'],
                ],
                'role' => 'tank',
            ]],
        ];

        $team = $this->mapper->mapRunDetailsRoster($data);

        $this->assertCount(1, $team);
        $this->assertNull($team[0]['equipped_item_level']);
    }
}
