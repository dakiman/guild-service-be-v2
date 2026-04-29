<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard;

use App\Blizzard\Mappers\CharacterStatsMapper;
use Tests\TestCase;

class CharacterStatsMapperTest extends TestCase
{
    public function test_strips_envelope_keys(): void
    {
        $mapper = new CharacterStatsMapper;
        $dto = $mapper->map([
            '_links' => ['self' => ['href' => 'x']],
            'character' => ['name' => 'TestCharacter'],
            'health' => 1000,
            'strength' => ['base' => 50, 'effective' => 75],
            'mastery' => 12.5,
        ]);

        $this->assertArrayNotHasKey('_links', $dto->fields);
        $this->assertArrayNotHasKey('character', $dto->fields);
        $this->assertSame(1000, $dto->health);
        $this->assertSame(75, $dto->strength);
        $this->assertSame(12.5, $dto->fields['mastery']);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $mapper = new CharacterStatsMapper;
        $dto = $mapper->map(['health' => 500]);

        $this->assertNull($dto->strength);
        $this->assertNull($dto->agility);
        $this->assertNull($dto->intellect);
        $this->assertSame(500, $dto->health);
    }
}
