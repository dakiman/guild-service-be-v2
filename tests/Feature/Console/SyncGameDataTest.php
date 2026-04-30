<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Models\GameDataFaction;
use Database\Seeders\GameDataExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncGameDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GameDataExpansionSeeder::class);
    }

    public function test_sync_factions_upserts_known_factions_with_expansion_id(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [
                ['id' => 2570, 'name' => 'Council of Dornogal'],
                ['id' => 2510, 'name' => 'Valdrakken Accord'],
                ['id' => 99999, 'name' => 'Unknown future faction'],
            ],
        ]);
        $mock->method('getFaction')->willReturnCallback(function (int $id): array {
            return match ($id) {
                2570 => ['id' => 2570, 'name' => 'Council of Dornogal', 'category' => ['id' => 1245]],
                2510 => ['id' => 2510, 'name' => 'Valdrakken Accord', 'category' => ['id' => 1234]],
                99999 => ['id' => 99999, 'name' => 'Unknown future faction'],
            };
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions'])
            ->assertExitCode(0);

        $this->assertSame(3, GameDataFaction::count());

        $tww = GameDataFaction::find(2570);
        $this->assertNotNull($tww);
        $this->assertSame(1, $tww->expansion_id, 'TWW faction maps to expansion 1');

        $df = GameDataFaction::find(2510);
        $this->assertSame(2, $df->expansion_id, 'Dragonflight faction maps to expansion 2');

        $unknown = GameDataFaction::find(99999);
        $this->assertNull($unknown->expansion_id, 'Unknown faction has null expansion_id');
    }

    public function test_sync_factions_is_idempotent(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [['id' => 2570, 'name' => 'Council of Dornogal']],
        ]);
        $mock->method('getFaction')->willReturn([
            'id' => 2570, 'name' => 'Council of Dornogal',
        ]);
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions']);
        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions']);

        $this->assertSame(1, GameDataFaction::count(), 'rerun should not duplicate rows');
    }

    public function test_sync_factions_continues_on_individual_id_failure(): void
    {
        $mock = $this->createMock(BlizzardGameDataClient::class);
        $mock->method('getFactionIndex')->willReturn([
            'factions' => [
                ['id' => 2570, 'name' => 'A'],
                ['id' => 2510, 'name' => 'B'],
            ],
        ]);
        $mock->method('getFaction')->willReturnCallback(function (int $id): ?array {
            if ($id === 2570) {
                throw new \RuntimeException('simulated transient failure');
            }

            return ['id' => $id, 'name' => 'B'];
        });
        $this->app->instance(BlizzardGameDataClient::class, $mock);

        $this->artisan('blizzard:sync-game-data', ['resource' => 'factions'])
            ->assertExitCode(0);

        $this->assertNull(GameDataFaction::find(2570));
        $this->assertNotNull(GameDataFaction::find(2510), 'second faction still upserted');
    }
}
