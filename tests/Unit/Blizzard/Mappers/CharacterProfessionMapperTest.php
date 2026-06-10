<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterProfessionMapper;
use PHPUnit\Framework\TestCase;

class CharacterProfessionMapperTest extends TestCase
{
    private CharacterProfessionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new CharacterProfessionMapper;
    }

    public function test_maps_khaz_algar_tier_to_war_within(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Khaz Algar Blacksmithing'], 'skill_points' => 80, 'max_skill_points' => 100],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $dtos);
        $this->assertSame(1, $dtos[0]->expansionId);
        $this->assertSame('Khaz Algar Blacksmithing', $dtos[0]->tierName);
    }

    public function test_maps_dragon_isles_tier_to_dragonflight(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Dragon Isles Blacksmithing'], 'skill_points' => 100, 'max_skill_points' => 100],
                    ],
                ],
            ],
        ]);

        $this->assertSame(2, $dtos[0]->expansionId);
    }

    public function test_maps_shadowlands_tier(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Shadowlands Blacksmithing'], 'skill_points' => 175, 'max_skill_points' => 175],
                    ],
                ],
            ],
        ]);

        $this->assertSame(3, $dtos[0]->expansionId);
    }

    public function test_bfa_kul_tiran_resolves_to_expansion_4(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Kul Tiran Blacksmithing'], 'skill_points' => 175, 'max_skill_points' => 175],
                    ],
                ],
            ],
        ]);

        $this->assertSame(4, $dtos[0]->expansionId);
    }

    public function test_bfa_zandalari_resolves_to_same_expansion_4_as_kul_tiran(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Zandalari Blacksmithing'], 'skill_points' => 175, 'max_skill_points' => 175],
                    ],
                ],
            ],
        ]);

        $this->assertSame(4, $dtos[0]->expansionId);
    }

    public function test_unprefixed_legacy_tier_yields_null_expansion(): void
    {
        // Tier name without any known prefix falls through to null
        // → FE renders in "Legacy" bucket.
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Blacksmithing'], 'skill_points' => 300, 'max_skill_points' => 300],
                    ],
                ],
            ],
        ]);

        $this->assertNull($dtos[0]->expansionId);
    }

    public function test_classic_prefixed_tier_resolves_to_expansion_11(): void
    {
        // Blizzard explicitly prefixes vanilla tiers with "Classic"
        // (e.g. "Classic Mining"); we map these to the Classic expansion.
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 186, 'name' => 'Mining'],
                    'tiers' => [
                        ['tier' => ['name' => 'Classic Mining'], 'skill_points' => 13, 'max_skill_points' => 300],
                    ],
                ],
            ],
        ]);

        $this->assertSame(11, $dtos[0]->expansionId);
    }

    public function test_midnight_prefixed_tier_resolves_to_war_within(): void
    {
        // "Midnight" tiers appear in the live response (e.g. "Midnight Mining");
        // current-patch content, so map to TWW (expansion 1).
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 186, 'name' => 'Mining'],
                    'tiers' => [
                        ['tier' => ['name' => 'Midnight Mining'], 'skill_points' => 100, 'max_skill_points' => 100],
                    ],
                ],
            ],
        ]);

        $this->assertSame(1, $dtos[0]->expansionId);
    }

    public function test_unknown_tier_prefix_yields_null_expansion(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Future Expansion Blacksmithing'], 'skill_points' => 1, 'max_skill_points' => 100],
                    ],
                ],
            ],
        ]);

        $this->assertNull($dtos[0]->expansionId);
    }

    public function test_expands_one_dto_per_tier(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                [
                    'profession' => ['id' => 164, 'name' => 'Blacksmithing'],
                    'tiers' => [
                        ['tier' => ['name' => 'Khaz Algar Blacksmithing'], 'skill_points' => 80, 'max_skill_points' => 100],
                        ['tier' => ['name' => 'Dragon Isles Blacksmithing'], 'skill_points' => 100, 'max_skill_points' => 100],
                        ['tier' => ['name' => 'Shadowlands Blacksmithing'], 'skill_points' => 175, 'max_skill_points' => 175],
                    ],
                ],
            ],
        ]);

        $this->assertCount(3, $dtos);
        $this->assertSame([1, 2, 3], array_map(fn ($d) => $d->expansionId, $dtos));
        $this->assertTrue($dtos[0]->isPrimary);
    }

    public function test_secondaries_are_marked_non_primary(): void
    {
        $dtos = $this->mapper->map([
            'secondaries' => [
                [
                    'profession' => ['id' => 185, 'name' => 'Cooking'],
                    'tiers' => [
                        ['tier' => ['name' => 'Khaz Algar Cooking'], 'skill_points' => 50, 'max_skill_points' => 100],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $dtos);
        $this->assertFalse($dtos[0]->isPrimary);
        $this->assertSame(1, $dtos[0]->expansionId);
    }

    public function test_null_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->mapper->map(null));
    }

    public function test_entry_with_zero_id_is_skipped(): void
    {
        $dtos = $this->mapper->map([
            'primaries' => [
                ['profession' => ['name' => 'Unknown'], 'tiers' => [['tier' => ['name' => 'X'], 'skill_points' => 1, 'max_skill_points' => 1]]],
            ],
        ]);

        $this->assertSame([], $dtos);
    }
}
