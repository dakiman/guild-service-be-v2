<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterMountMapper;
use Tests\TestCase;

class CharacterMountMapperTest extends TestCase
{
    public function test_maps_mount_entries(): void
    {
        $payload = [
            'mounts' => [
                ['mount' => ['id' => 6, 'name' => 'Brown Horse'], 'is_useable' => true],
                ['mount' => ['id' => 64, 'name' => 'Red Wolf'], 'is_useable' => false],
            ],
        ];

        $dtos = (new CharacterMountMapper)->map($payload);

        $this->assertCount(2, $dtos);
        $this->assertSame(6, $dtos[0]->mountId);
        $this->assertSame('Brown Horse', $dtos[0]->name);
        $this->assertTrue($dtos[0]->isUseable);
        $this->assertSame(64, $dtos[1]->mountId);
        $this->assertFalse($dtos[1]->isUseable);
    }

    public function test_returns_empty_for_null_or_missing_mounts(): void
    {
        $this->assertSame([], (new CharacterMountMapper)->map(null));
        $this->assertSame([], (new CharacterMountMapper)->map([]));
        $this->assertSame([], (new CharacterMountMapper)->map(['mounts' => []]));
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $payload = ['mounts' => [
            ['mount' => ['id' => 0, 'name' => 'broken'], 'is_useable' => true],
            ['mount' => ['id' => 6, 'name' => 'Brown Horse'], 'is_useable' => true],
        ]];

        $dtos = (new CharacterMountMapper)->map($payload);

        $this->assertCount(1, $dtos);
        $this->assertSame(6, $dtos[0]->mountId);
    }
}
