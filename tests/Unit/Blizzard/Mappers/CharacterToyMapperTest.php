<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterToyMapper;
use Tests\TestCase;

class CharacterToyMapperTest extends TestCase
{
    public function test_maps_toy_entries(): void
    {
        $payload = [
            'toys' => [
                ['toy' => ['id' => 54343, 'name' => 'X-52 Rocket Pack']],
                ['toy' => ['id' => 88589, 'name' => 'Hearthstone Toy']],
            ],
        ];

        $dtos = (new CharacterToyMapper)->map($payload);

        $this->assertCount(2, $dtos);
        $this->assertSame(54343, $dtos[0]->toyId);
        $this->assertSame('X-52 Rocket Pack', $dtos[0]->name);
        $this->assertSame(88589, $dtos[1]->toyId);
    }

    public function test_returns_empty_for_null_or_missing_toys(): void
    {
        $this->assertSame([], (new CharacterToyMapper)->map(null));
        $this->assertSame([], (new CharacterToyMapper)->map([]));
        $this->assertSame([], (new CharacterToyMapper)->map(['toys' => []]));
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $payload = ['toys' => [
            ['toy' => ['id' => 0, 'name' => 'broken']],
            ['toy' => ['id' => 5, 'name' => 'good']],
        ]];

        $dtos = (new CharacterToyMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(5, $dtos[0]->toyId);
    }
}
