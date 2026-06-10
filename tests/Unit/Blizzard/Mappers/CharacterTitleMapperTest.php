<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\CharacterTitleMapper;
use Tests\TestCase;

class CharacterTitleMapperTest extends TestCase
{
    private CharacterTitleMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new CharacterTitleMapper;
    }

    public function test_returns_empty_array_for_null_payload(): void
    {
        $result = $this->mapper->map(null);
        $this->assertSame([], $result['titles']);
        $this->assertNull($result['activeTitleId']);
    }

    public function test_returns_empty_array_for_empty_titles(): void
    {
        $result = $this->mapper->map(['titles' => []]);
        $this->assertSame([], $result['titles']);
        $this->assertNull($result['activeTitleId']);
    }

    public function test_maps_titles_and_extracts_active_id(): void
    {
        $data = [
            'active_title' => ['id' => 256, 'name' => 'Loremaster', 'display_string' => 'Loremaster %s'],
            'titles' => [
                ['id' => 71, 'name' => 'Champion of the Frozen Wastes'],
                ['id' => 256, 'name' => 'Loremaster'],
            ],
        ];

        $result = $this->mapper->map($data);

        $this->assertCount(2, $result['titles']);
        $this->assertSame(256, $result['activeTitleId']);

        $this->assertSame(71, $result['titles'][0]->titleId);
        $this->assertSame('Champion of the Frozen Wastes', $result['titles'][0]->name);

        $this->assertSame(256, $result['titles'][1]->titleId);
        $this->assertSame('Loremaster', $result['titles'][1]->name);
    }

    public function test_skips_entries_with_zero_id(): void
    {
        $data = [
            'titles' => [
                ['id' => 0, 'name' => 'Bogus'],
                ['id' => 7, 'name' => 'Real'],
            ],
        ];

        $result = $this->mapper->map($data);
        $this->assertCount(1, $result['titles']);
        $this->assertSame(7, $result['titles'][0]->titleId);
    }

    public function test_no_active_title_means_null_active_id(): void
    {
        $data = [
            'titles' => [
                ['id' => 71, 'name' => 'Champion'],
                ['id' => 256, 'name' => 'Loremaster'],
            ],
        ];

        $result = $this->mapper->map($data);
        $this->assertCount(2, $result['titles']);
        $this->assertNull($result['activeTitleId']);
    }

    public function test_uses_per_entry_display_string_when_available(): void
    {
        $data = [
            'titles' => [
                ['id' => 9, 'name' => 'The Patient', 'display_string' => '%s the Patient'],
            ],
        ];

        $result = $this->mapper->map($data);
        $this->assertSame('%s the Patient', $result['titles'][0]->displayString);
    }
}
