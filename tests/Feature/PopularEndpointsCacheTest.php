<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The popular endpoints cache their payload in a serializing store in
 * production (Redis) with cache.serializable_classes = false, so anything
 * cached MUST be plain arrays/scalars — objects come back as
 * __PHP_Incomplete_Class and 500 every cache-hit request. The suite's
 * default array store never serializes and can't catch that, so these
 * tests run against the file store, which round-trips through
 * serialize()/unserialize() exactly like Redis.
 */
class PopularEndpointsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file']);
        Cache::store('file')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();

        parent::tearDown();
    }

    public function test_characters_popular_survives_cache_hit(): void
    {
        Character::factory()->create([
            'last_searched_at' => now(),
            'num_of_searches' => 5,
        ]);

        $miss = $this->getJson('/api/v1/characters/popular');
        $miss->assertOk();

        $hit = $this->getJson('/api/v1/characters/popular');
        $hit->assertOk();

        $this->assertSame($miss->json(), $hit->json());
        $this->assertNotEmpty($hit->json('recently_searched'));
    }

    public function test_guilds_popular_survives_cache_hit(): void
    {
        Guild::factory()->create([
            'last_searched_at' => now(),
            'num_of_searches' => 5,
        ]);

        $miss = $this->getJson('/api/v1/guilds/popular');
        $miss->assertOk();

        $hit = $this->getJson('/api/v1/guilds/popular');
        $hit->assertOk();

        $this->assertSame($miss->json(), $hit->json());
        $this->assertNotEmpty($hit->json('recently_searched'));
    }
}
