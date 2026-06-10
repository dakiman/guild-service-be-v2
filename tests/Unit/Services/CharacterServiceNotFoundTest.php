<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\EntityNotFoundException;
use App\Services\CharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CharacterServiceNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_throws_entity_not_found_when_marker_present_and_no_row(): void
    {
        Cache::put('blizzard:not-found:character:eu:the-maelstrom:zzz', true, 60);

        $this->expectException(EntityNotFoundException::class);

        app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'zzz');
    }

    public function test_returns_null_and_does_not_throw_when_marker_absent_and_no_row(): void
    {
        $result = app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'zzz');

        $this->assertNull($result);
    }
}
