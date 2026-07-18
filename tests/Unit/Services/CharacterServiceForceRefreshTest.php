<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `?refresh=1` must recover a character from a stale "not found" verdict:
 * Blizzard 404s are re-tried, not permanently trusted, once a user explicitly
 * asks for fresh data.
 */
class CharacterServiceForceRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_not_found_marker_with_force_refresh_returns_null_and_forgets_marker(): void
    {
        Queue::fake();

        Cache::put('blizzard:not-found:character:eu:the-maelstrom:ghost', true, 60);

        $result = app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'ghost', forceRefresh: true);

        $this->assertNull($result);
        $this->assertFalse(
            Cache::has('blizzard:not-found:character:eu:the-maelstrom:ghost'),
            'not-found marker must be forgotten on force-refresh',
        );
    }

    public function test_not_found_without_marker_and_force_refresh_returns_null_silently(): void
    {
        Queue::fake();

        $result = app(CharacterService::class)->getByIdentity('eu', 'the-maelstrom', 'never-existed', forceRefresh: true);

        $this->assertNull($result);
    }
}
