<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class GuildControllerPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_is_clamped_to_100(): void
    {
        Bus::fake();

        $guild = Guild::factory()->create([
            'name' => 'echo',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();

        GuildMember::factory()->count(125)->create(['guild_id' => $guild->id]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo?per_page=500');

        $response->assertOk();
        $this->assertSame(100, $response->json('members.per_page'));
        $this->assertCount(100, $response->json('members.data'));
    }

    public function test_filter_longer_than_64_chars_is_rejected(): void
    {
        $this->getJson('/api/v1/guilds/eu/tarren-mill/echo?filter='.str_repeat('a', 65))
            ->assertUnprocessable();
    }

    public function test_negative_per_page_is_rejected(): void
    {
        $this->getJson('/api/v1/guilds/eu/tarren-mill/echo?per_page=-5')
            ->assertUnprocessable();
    }
}
