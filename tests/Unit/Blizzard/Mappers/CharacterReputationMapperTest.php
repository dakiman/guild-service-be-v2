<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterReputationMapper;
use Tests\TestCase;

class CharacterReputationMapperTest extends TestCase
{
    private function blizzardPayload(): array
    {
        return [
            'character' => ['name' => 'Cirna'],
            'reputations' => [
                [
                    'faction' => ['id' => 2510, 'name' => 'Valdrakken Accord'],
                    'standing' => [
                        'raw' => 38000,
                        'value' => 0,
                        'max' => 21000,
                        'tier' => 7,
                        'name' => 'Exalted',
                    ],
                ],
                [
                    'faction' => ['id' => 2511, 'name' => 'Iskaara Tuskarr'],
                    'standing' => [
                        'raw' => 9500,
                        'value' => 3500,
                        'max' => 12000,
                        'tier' => 5,
                        'name' => 'Honored',
                    ],
                ],
                // Edge: missing faction id — should be skipped.
                [
                    'faction' => ['name' => 'Mystery Faction'],
                    'standing' => ['raw' => 0, 'max' => 3000, 'name' => 'Neutral'],
                ],
            ],
        ];
    }

    public function test_maps_each_faction_to_dto(): void
    {
        $dtos = (new CharacterReputationMapper)->map($this->blizzardPayload());

        $this->assertCount(2, $dtos);

        $this->assertSame(2510, $dtos[0]->factionId);
        $this->assertSame('Valdrakken Accord', $dtos[0]->factionName);
        $this->assertSame('exalted', $dtos[0]->standing);
        $this->assertSame(38000, $dtos[0]->value);
        $this->assertSame(21000, $dtos[0]->max);

        $this->assertSame(2511, $dtos[1]->factionId);
        $this->assertSame('honored', $dtos[1]->standing);
        $this->assertSame(9500, $dtos[1]->value);
        $this->assertSame(12000, $dtos[1]->max);
    }

    public function test_returns_empty_array_for_null_input(): void
    {
        $this->assertSame([], (new CharacterReputationMapper)->map(null));
    }

    public function test_returns_empty_array_for_payload_without_reputations_key(): void
    {
        $this->assertSame([], (new CharacterReputationMapper)->map(['character' => []]));
    }
}
