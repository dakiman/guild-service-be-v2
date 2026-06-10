<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterPetMapper;
use Tests\TestCase;

class CharacterPetMapperTest extends TestCase
{
    public function test_maps_pet_entries(): void
    {
        $payload = [
            'pets' => [
                [
                    'id' => 4242,
                    'species' => [
                        'id' => 1455,
                        'name' => "Lil' K.T.",
                        'creature_display' => ['id' => 28168],
                    ],
                    'level' => 25,
                    'quality' => ['type' => 'RARE', 'name' => 'Rare'],
                    'stats' => ['breed_id' => 9],
                    'is_favorite' => true,
                ],
            ],
        ];

        $dtos = (new CharacterPetMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(4242, $dtos[0]->petId);
        $this->assertSame(1455, $dtos[0]->speciesId);
        $this->assertSame("Lil' K.T.", $dtos[0]->name);
        $this->assertSame(25, $dtos[0]->level);
        $this->assertSame(9, $dtos[0]->breedId);
        $this->assertSame('rare', $dtos[0]->quality);
        $this->assertTrue($dtos[0]->isFavorite);
        $this->assertSame(28168, $dtos[0]->creatureDisplayId);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $payload = [
            'pets' => [
                [
                    'id' => 100,
                    'species' => ['id' => 200, 'name' => 'Unknown'],
                    'level' => 1,
                ],
            ],
        ];

        $dtos = (new CharacterPetMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertNull($dtos[0]->breedId);
        $this->assertNull($dtos[0]->quality);
        $this->assertFalse($dtos[0]->isFavorite);
        $this->assertNull($dtos[0]->creatureDisplayId);
    }

    public function test_returns_empty_for_null_or_missing_pets(): void
    {
        $this->assertSame([], (new CharacterPetMapper)->map(null));
        $this->assertSame([], (new CharacterPetMapper)->map([]));
        $this->assertSame([], (new CharacterPetMapper)->map(['pets' => []]));
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $payload = ['pets' => [
            ['id' => 0, 'species' => ['id' => 1, 'name' => 'x'], 'level' => 1],
            ['id' => 9, 'species' => ['id' => 2, 'name' => 'good'], 'level' => 25],
        ]];

        $dtos = (new CharacterPetMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(9, $dtos[0]->petId);
    }
}
