<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\RaidEncounterKillMapper;
use PHPUnit\Framework\TestCase;

class RaidEncounterKillMapperTest extends TestCase
{
    private RaidEncounterKillMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new RaidEncounterKillMapper;
    }

    /**
     * @param  array<int, array{name: string, kills: array<int, array{int, string, int}>}>  $expansions
     *                                                                                                   each kill tuple: [encounterId, difficulty, completedCount]
     */
    private function payload(array $expansions): array
    {
        return [
            'expansions' => array_map(fn ($exp) => [
                'expansion' => ['name' => $exp['name']],
                'instances' => [
                    [
                        'instance' => ['id' => 1302, 'name' => 'The Voidspire'],
                        'modes' => array_map(fn ($kill) => [
                            'difficulty' => ['type' => $kill[1]],
                            'progress' => [
                                'encounters' => [
                                    [
                                        'encounter' => ['id' => $kill[0], 'name' => "Boss {$kill[0]}"],
                                        'completed_count' => $kill[2],
                                        'last_kill_timestamp' => 1700000000,
                                    ],
                                ],
                            ],
                        ], $exp['kills']),
                    ],
                ],
            ], $expansions),
        ];
    }

    public function test_maps_encounters_across_expansions(): void
    {
        $dtos = $this->mapper->map($this->payload([
            ['name' => 'Midnight', 'kills' => [[3001, 'NORMAL', 4], [3002, 'HEROIC', 2]]],
            ['name' => 'Legion', 'kills' => [[2001, 'NORMAL', 7]]],
        ]));

        $this->assertCount(3, $dtos);
        $this->assertSame('Midnight', $dtos[0]->expansionName);
        $this->assertSame('normal', $dtos[0]->difficulty);
        $this->assertSame('Legion', $dtos[2]->expansionName);
    }

    public function test_current_season_duplicate_collapses_into_real_expansion(): void
    {
        // Blizzard lists current raids under BOTH the real expansion and the
        // synthetic "Current Season" grouping. Duplicate (encounter, difficulty)
        // keys in one upsert batch abort the whole raids-slice transaction
        // (SQLSTATE 21000), so the mapper must emit exactly one DTO per key,
        // tagged with the real expansion so the stats heatmap can see it.
        $dtos = $this->mapper->map($this->payload([
            ['name' => 'Current Season', 'kills' => [[3001, 'NORMAL', 4]]],
            ['name' => 'Midnight', 'kills' => [[3001, 'NORMAL', 4]]],
        ]));

        $this->assertCount(1, $dtos);
        $this->assertSame('Midnight', $dtos[0]->expansionName);
    }

    public function test_real_expansion_wins_regardless_of_grouping_order(): void
    {
        $dtos = $this->mapper->map($this->payload([
            ['name' => 'Midnight', 'kills' => [[3001, 'NORMAL', 4]]],
            ['name' => 'Current Season', 'kills' => [[3001, 'NORMAL', 4]]],
        ]));

        $this->assertCount(1, $dtos);
        $this->assertSame('Midnight', $dtos[0]->expansionName);
    }

    public function test_current_season_only_encounter_is_kept(): void
    {
        // A raid listed only under "Current Season" (no expansion grouping
        // yet) must still be persisted.
        $dtos = $this->mapper->map($this->payload([
            ['name' => 'Current Season', 'kills' => [[3001, 'NORMAL', 4]]],
        ]));

        $this->assertCount(1, $dtos);
        $this->assertSame('Current Season', $dtos[0]->expansionName);
    }

    public function test_same_encounter_different_difficulties_both_kept(): void
    {
        $dtos = $this->mapper->map($this->payload([
            ['name' => 'Current Season', 'kills' => [[3001, 'NORMAL', 4], [3001, 'HEROIC', 1]]],
            ['name' => 'Midnight', 'kills' => [[3001, 'NORMAL', 4], [3001, 'HEROIC', 1]]],
        ]));

        $this->assertCount(2, $dtos);
        $this->assertSame(['normal', 'heroic'], array_map(fn ($d) => $d->difficulty, $dtos));
        $this->assertSame(['Midnight', 'Midnight'], array_map(fn ($d) => $d->expansionName, $dtos));
    }

    public function test_null_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->mapper->map(null));
    }

    public function test_skips_entries_missing_ids_or_difficulty(): void
    {
        $dtos = $this->mapper->map([
            'expansions' => [
                [
                    'expansion' => ['name' => 'Midnight'],
                    'instances' => [
                        [
                            'instance' => ['name' => 'No Id'],
                            'modes' => [
                                [
                                    'difficulty' => ['type' => 'NORMAL'],
                                    'progress' => ['encounters' => [['encounter' => ['id' => 3001, 'name' => 'X'], 'completed_count' => 1]]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $dtos);
    }
}
