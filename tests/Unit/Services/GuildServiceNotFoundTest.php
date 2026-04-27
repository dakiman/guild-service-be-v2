<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\EntityNotFoundException;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GuildServiceNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_throws_entity_not_found_when_marker_present_and_no_row(): void
    {
        Cache::put('blizzard:not-found:guild:us:illidan:liquid-disbanded', true, 60);

        $this->expectException(EntityNotFoundException::class);

        app(GuildService::class)->getByIdentity('us', 'illidan', 'liquid-disbanded');
    }

    public function test_returns_null_when_marker_absent_and_no_row(): void
    {
        $this->assertNull(app(GuildService::class)->getByIdentity('us', 'illidan', 'no-such-guild'));
    }
}
