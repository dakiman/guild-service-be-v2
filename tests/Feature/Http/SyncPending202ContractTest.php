<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncPending202ContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_unknown_character_202_has_queue_depth_and_quiet_retry_after(): void
    {
        $this->getJson('/api/v1/characters/eu/silvermoon/nosuchcharacter')
            ->assertStatus(202)
            ->assertHeader('Retry-After', '10')
            ->assertJsonStructure(['message', 'queue_depth']);
    }

    public function test_busy_queue_scales_character_retry_after_to_30(): void
    {
        Queue::shouldReceive('size')->with('blizzard-user-sync')->andReturn(500);

        $this->getJson('/api/v1/characters/eu/silvermoon/nosuchcharacter')
            ->assertStatus(202)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['queue_depth' => 500]);
    }

    public function test_unknown_guild_202_has_queue_depth_and_quiet_retry_after(): void
    {
        $this->getJson('/api/v1/guilds/eu/silvermoon/no-such-guild')
            ->assertStatus(202)
            ->assertHeader('Retry-After', '5')
            ->assertJsonStructure(['message', 'queue_depth']);
    }

    public function test_busy_queue_scales_guild_retry_after_to_30(): void
    {
        Queue::shouldReceive('size')->with('blizzard-user-sync')->andReturn(500);

        $this->getJson('/api/v1/guilds/eu/silvermoon/no-such-guild')
            ->assertStatus(202)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['queue_depth' => 500]);
    }
}
