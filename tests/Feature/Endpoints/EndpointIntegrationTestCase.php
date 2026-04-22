<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class EndpointIntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Retail character fixtures. Fill in realm and name per slot; the slot key
     * describes the data shape that character is expected to exercise.
     *
     * @var array<string, array{region: string, realm: string, name: string}>
     */
    public const RETAIL_CHARACTERS = [
        'geared_main' => ['region' => 'eu', 'realm' => '', 'name' => ''], // sockets + enchants + tier set
        'pvp_player' => ['region' => 'eu', 'realm' => '', 'name' => ''], // active PvP
        'profession_rich' => ['region' => 'eu', 'realm' => '', 'name' => ''], // 2 primaries + secondaries
        'raider' => ['region' => 'eu', 'realm' => '', 'name' => ''], // active raider
    ];

    /**
     * @var array<string, array{region: string, realm: string, name: string}>
     */
    public const CLASSIC_CHARACTERS = [
        'vanilla_era' => ['region' => 'eu', 'realm' => '', 'name' => ''],
        'cata_classic' => ['region' => 'eu', 'realm' => '', 'name' => ''],
    ];

    /**
     * @var array<int, array{region: string, realm: string, name: string}>
     */
    public const GUILDS = [
        ['region' => 'eu', 'realm' => '', 'name' => ''],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('BLIZZARD_CLIENT_ID') || ! env('BLIZZARD_CLIENT_SECRET')) {
            $this->markTestSkipped('Blizzard credentials not configured (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET).');
        }
    }

    /**
     * Skip a single test if its fixture has empty realm or name.
     *
     * @param  array{region: string, realm: string, name: string}  $fixture
     */
    protected function requireFixture(array $fixture, string $slot): void
    {
        if ($fixture['realm'] === '' || $fixture['name'] === '') {
            $this->markTestSkipped("Fixture '{$slot}' has an empty realm or name. Fill it in on EndpointIntegrationTestCase to exercise this test.");
        }
    }
}
