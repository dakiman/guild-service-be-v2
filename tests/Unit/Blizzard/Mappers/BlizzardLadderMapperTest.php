<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\BlizzardLadderMapper;
use PHPUnit\Framework\TestCase;

class BlizzardLadderMapperTest extends TestCase
{
    private BlizzardLadderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new BlizzardLadderMapper;
    }

    private function member(int $profileId, ?int $specId, string $name = 'Char'): array
    {
        return [
            'profile' => ['id' => $profileId, 'name' => $name, 'realm' => ['id' => 1596, 'slug' => 'the-maelstrom']],
            'faction' => ['type' => 'HORDE'],
            'specialization' => ['id' => $specId],
        ];
    }

    private function payload(array $groups): array
    {
        return [
            'leading_groups' => $groups,
            'keystone_affixes' => [
                ['keystone_affix' => ['id' => 9, 'name' => 'Tyrannical'], 'starting_level' => 4],
                ['keystone_affix' => ['id' => 10, 'name' => 'Fortified'], 'starting_level' => 7],
            ],
        ];
    }

    private const UPGRADES = [
        ['upgrade_level' => 1, 'qualifying_duration' => 1800000],
        ['upgrade_level' => 2, 'qualifying_duration' => 1440000],
        ['upgrade_level' => 3, 'qualifying_duration' => 1080000],
    ];

    public function test_maps_group_with_timing_affixes_and_comp(): void
    {
        $group = [
            'ranking' => 1, 'duration' => 1650000, 'completed_timestamp' => 1754300000000, 'keystone_level' => 15,
            'members' => [
                $this->member(1, 268), $this->member(2, 65),
                $this->member(3, 102), $this->member(4, 253), $this->member(5, 577),
            ],
        ];

        $mapped = $this->mapper->mapLeaderboard($this->payload([$group]), 1002, 'eu', 504, self::UPGRADES);

        $this->assertCount(1, $mapped);
        $run = $mapped[0]['run'];
        $this->assertTrue($run['is_completed_on_time']);
        $this->assertArrayNotHasKey('affixes', $run);
        $this->assertSame([9, 10], $this->mapper->affixIds($this->payload([$group])));
        $this->assertSame('268:65:102,253,577', $run['comp_signature']);
        $this->assertSame(1002, $run['period_id']);
        $this->assertCount(5, $mapped[0]['members']);
        $this->assertSame('the-maelstrom', $mapped[0]['members'][0]['realm_slug']);
    }

    public function test_hash_is_member_order_independent(): void
    {
        $members = [
            ['profile_id' => 5, 'name' => 'E', 'realm_slug' => 'x'],
            ['profile_id' => 1, 'name' => 'A', 'realm_slug' => 'x'],
        ];

        $this->assertSame(
            $this->mapper->runHash(504, 1754300000000, 1650000, $members),
            $this->mapper->runHash(504, 1754300000000, 1650000, array_reverse($members)),
        );
    }

    public function test_overtime_run_and_missing_timer(): void
    {
        $group = [
            'duration' => 1900000, 'completed_timestamp' => 1754300000000, 'keystone_level' => 15,
            'members' => [$this->member(1, 268)],
        ];

        $mapped = $this->mapper->mapLeaderboard($this->payload([$group]), 1002, 'eu', 504, self::UPGRADES);
        $this->assertFalse($mapped[0]['run']['is_completed_on_time']);

        $mapped = $this->mapper->mapLeaderboard($this->payload([$group]), 1002, 'eu', 504, null);
        $this->assertNull($mapped[0]['run']['is_completed_on_time']);
    }

    public function test_timed_is_null_when_timer_unknown(): void
    {
        $runs = $this->mapper->mapLeaderboard($this->payload([[
            'duration' => 1650000, 'completed_timestamp' => 1754300000000, 'keystone_level' => 15,
            'members' => [$this->member(1, 268)],
        ]]), 1002, 'eu', 504, null);

        $this->assertNull($runs[0]['run']['is_completed_on_time']);
    }

    public function test_irregular_comp_yields_null_signature(): void
    {
        // two tanks
        $this->assertNull($this->mapper->compSignature([
            ['spec_id' => 268], ['spec_id' => 73], ['spec_id' => 102], ['spec_id' => 253], ['spec_id' => 65],
        ]));
        // four members
        $this->assertNull($this->mapper->compSignature([
            ['spec_id' => 268], ['spec_id' => 65], ['spec_id' => 102], ['spec_id' => 253],
        ]));
        // unknown spec
        $this->assertNull($this->mapper->compSignature([
            ['spec_id' => 268], ['spec_id' => 65], ['spec_id' => 102], ['spec_id' => 253], ['spec_id' => null],
        ]));
    }

    public function test_skips_groups_with_missing_core_fields(): void
    {
        $mapped = $this->mapper->mapLeaderboard(
            $this->payload([['duration' => 0, 'completed_timestamp' => 0, 'keystone_level' => 0, 'members' => []]]),
            1002, 'eu', 504, self::UPGRADES,
        );
        $this->assertSame([], $mapped);
    }
}
