<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GameDataTitleMapper;
use PHPUnit\Framework\TestCase;

class GameDataTitleMapperTest extends TestCase
{
    private GameDataTitleMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GameDataTitleMapper;
    }

    public function test_extracts_gender_specific_strings_when_present(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 414,
            'name' => '{name}, the Bear',
            'gender_name' => [
                'male' => '{name}, Lord of the Bears',
                'female' => '{name}, Lady of the Bears',
            ],
        ]);

        $this->assertSame(414, $dto->id);
        $this->assertSame('{name}, Lord of the Bears', $dto->nameMale);
        $this->assertSame('{name}, Lady of the Bears', $dto->nameFemale);
    }

    public function test_falls_back_to_name_when_gender_name_missing(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 100,
            'name' => '{name}, the Hallowed',
        ]);

        $this->assertSame('{name}, the Hallowed', $dto->nameMale);
        $this->assertSame('{name}, the Hallowed', $dto->nameFemale);
    }

    public function test_falls_back_to_name_when_gender_name_partial(): void
    {
        $dto = $this->mapper->mapDetail([
            'id' => 200,
            'name' => '{name}, Champion',
            'gender_name' => [
                'male' => '{name}, the Champion',
            ],
        ]);

        $this->assertSame('{name}, the Champion', $dto->nameMale);
        $this->assertSame('{name}, Champion', $dto->nameFemale);
    }

    public function test_null_input_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(null));
    }

    public function test_input_without_id_returns_null_dto(): void
    {
        $this->assertNull($this->mapper->mapDetail(['name' => 'Anonymous']));
    }

    public function test_extracts_index_ids(): void
    {
        $ids = $this->mapper->extractIndexIds([
            'titles' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 414, 'name' => 'B'],
                ['name' => 'C-no-id'],
            ],
        ]);

        $this->assertSame([1, 414], $ids);
    }

    public function test_extract_index_handles_null_input(): void
    {
        $this->assertSame([], $this->mapper->extractIndexIds(null));
    }
}
