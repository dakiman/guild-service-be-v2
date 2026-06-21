<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Jobs;

use App\Blizzard\Client\BlizzardProfileClient;
use App\Blizzard\DTO\CharacterProfession;
use App\Blizzard\DTO\CharacterReputation;
use App\Blizzard\DTO\PvpBracketStats;
use App\Blizzard\DTO\RaidEncounterKill;
use App\Blizzard\Jobs\SyncCharacterData;
use App\Blizzard\Mappers\CharacterProfessionMapper;
use App\Blizzard\Mappers\CharacterReputationMapper;
use App\Blizzard\Mappers\PvpBracketStatsMapper;
use App\Blizzard\Mappers\RaidEncounterKillMapper;
use App\Enums\SyncDepth;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterization tests for the per-slice persistence (upsert + delete-missing).
 * These lock the observable behaviour — idempotent re-sync, prune stale, keep
 * legitimately-distinct composite-key rows — so the P2.1 bulk-upsert refactor is
 * provably behaviour-preserving.
 */
final class SyncSlicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('blizzard.sync.professions_enabled', true);
        Config::set('blizzard.sync.raids_enabled', true);
        Config::set('blizzard.sync.pvp_enabled', true);
    }

    private function job(): SyncCharacterData
    {
        return new SyncCharacterData('eu', 'kazzak', 'tester', SyncDepth::Full);
    }

    /**
     * @param  array<int, object>  $dtos
     */
    private function invoke(string $method, object $client, object $mapper, Character $character): void
    {
        $ref = new \ReflectionMethod(SyncCharacterData::class, $method);
        $ref->setAccessible(true);
        $ref->invoke($this->job(), $client, $mapper, $character);
    }

    public function test_professions_resync_prunes_stale_keeps_distinct_composite_rows(): void
    {
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterProfessions')->willReturn([]);

        // First sync: same profession id across two tiers (distinct rows) + a second profession.
        $mapper = $this->createMock(CharacterProfessionMapper::class);
        $mapper->method('map')->willReturn([
            new CharacterProfession(164, 'Blacksmithing', 'Khaz Algar', 100, 100, true),
            new CharacterProfession(164, 'Blacksmithing', 'Dragon Isles', 100, 100, true),
            new CharacterProfession(171, 'Alchemy', 'Khaz Algar', 50, 100, true),
        ]);
        $this->invoke('syncProfessions', $client, $mapper, $character);

        $this->assertSame(3, DB::table('character_professions')->where('character_id', $character->id)->count());
        $this->assertDatabaseHas('character_professions', ['character_id' => $character->id, 'profession_id' => 164, 'tier_name' => 'Dragon Isles']);

        // Second sync: drop the Dragon Isles tier + Alchemy, update Khaz Algar BS, add Enchanting.
        $mapper2 = $this->createMock(CharacterProfessionMapper::class);
        $mapper2->method('map')->willReturn([
            new CharacterProfession(164, 'Blacksmithing', 'Khaz Algar', 120, 120, true),
            new CharacterProfession(333, 'Enchanting', 'Khaz Algar', 30, 100, false),
        ]);
        $this->invoke('syncProfessions', $client, $mapper2, $character);

        $this->assertSame(2, DB::table('character_professions')->where('character_id', $character->id)->count());
        $this->assertDatabaseMissing('character_professions', ['character_id' => $character->id, 'profession_id' => 164, 'tier_name' => 'Dragon Isles']);
        $this->assertDatabaseMissing('character_professions', ['character_id' => $character->id, 'profession_id' => 171]);
        $this->assertDatabaseHas('character_professions', ['character_id' => $character->id, 'profession_id' => 164, 'tier_name' => 'Khaz Algar', 'skill_points' => 120]);
        $this->assertDatabaseHas('character_professions', ['character_id' => $character->id, 'profession_id' => 333]);
    }

    public function test_raids_resync_prunes_stale_keeps_distinct_difficulty_rows(): void
    {
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterRaidEncounters')->willReturn([]);

        // Same encounter on two difficulties (distinct rows) + a second encounter.
        $mapper = $this->createMock(RaidEncounterKillMapper::class);
        $mapper->method('map')->willReturn([
            new RaidEncounterKill('TWW', 1273, 'Nerubar', 2902, 'Ulgrax', 'Heroic', 3, 1700000000),
            new RaidEncounterKill('TWW', 1273, 'Nerubar', 2902, 'Ulgrax', 'Mythic', 1, 1700000100),
            new RaidEncounterKill('TWW', 1273, 'Nerubar', 2917, 'Silken', 'Heroic', 2, 1700000200),
        ]);
        $this->invoke('syncRaidEncounters', $client, $mapper, $character);

        $this->assertSame(3, DB::table('raid_encounter_kills')->where('character_id', $character->id)->count());
        $this->assertDatabaseHas('raid_encounter_kills', ['character_id' => $character->id, 'encounter_id' => 2902, 'difficulty' => 'Mythic']);

        // Drop the Mythic kill + Silken, bump the Heroic Ulgrax count.
        $mapper2 = $this->createMock(RaidEncounterKillMapper::class);
        $mapper2->method('map')->willReturn([
            new RaidEncounterKill('TWW', 1273, 'Nerubar', 2902, 'Ulgrax', 'Heroic', 5, 1700000300),
        ]);
        $this->invoke('syncRaidEncounters', $client, $mapper2, $character);

        $this->assertSame(1, DB::table('raid_encounter_kills')->where('character_id', $character->id)->count());
        $this->assertDatabaseMissing('raid_encounter_kills', ['character_id' => $character->id, 'encounter_id' => 2902, 'difficulty' => 'Mythic']);
        $this->assertDatabaseHas('raid_encounter_kills', ['character_id' => $character->id, 'encounter_id' => 2902, 'difficulty' => 'Heroic', 'completed_count' => 5]);
    }

    public function test_reputations_resync_prunes_and_updates(): void
    {
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterReputations')->willReturn([]);

        $mapper = $this->createMock(CharacterReputationMapper::class);
        $mapper->method('map')->willReturn([
            new CharacterReputation(2570, 'Dornogal', 'Honored', 5000, 12000),
            new CharacterReputation(2594, 'Hallowfall', 'Friendly', 3000, 6000),
        ]);
        $this->invoke('syncReputations', $client, $mapper, $character);
        $this->assertSame(2, DB::table('character_reputations')->where('character_id', $character->id)->count());

        $mapper2 = $this->createMock(CharacterReputationMapper::class);
        $mapper2->method('map')->willReturn([
            new CharacterReputation(2570, 'Dornogal', 'Revered', 9000, 12000),
        ]);
        $this->invoke('syncReputations', $client, $mapper2, $character);

        $this->assertSame(1, DB::table('character_reputations')->where('character_id', $character->id)->count());
        $this->assertDatabaseMissing('character_reputations', ['character_id' => $character->id, 'faction_id' => 2594]);
        $this->assertDatabaseHas('character_reputations', ['character_id' => $character->id, 'faction_id' => 2570, 'standing' => 'Revered', 'value' => 9000]);
    }

    public function test_pvp_resync_prunes_and_updates(): void
    {
        $character = Character::factory()->create();

        // map($slug, $body) is called once per chunked body; key the DTO by slug.
        $first = [
            '2v2' => new PvpBracketStats('2v2', 1800, 10, 5, 15, 2, 1, 3, 'Combatant'),
            '3v3' => new PvpBracketStats('3v3', 2100, 20, 10, 30, 4, 2, 6, 'Rival'),
        ];
        $client1 = $this->createStub(BlizzardProfileClient::class);
        $client1->method('getCharacterPvpSummary')->willReturn(['brackets' => []]);
        $client1->method('getCharacterPvpBracketsChunked')->willReturn(['2v2' => [], '3v3' => []]);
        $mapper = $this->createMock(PvpBracketStatsMapper::class);
        $mapper->method('map')->willReturnCallback(fn ($slug, $body) => $first[$slug] ?? null);
        $this->invoke('syncPvpData', $client1, $mapper, $character);
        $this->assertSame(2, DB::table('character_pvp_brackets')->where('character_id', $character->id)->count());

        $second = ['2v2' => new PvpBracketStats('2v2', 1900, 12, 5, 17, 2, 1, 3, 'Challenger')];
        $client2 = $this->createStub(BlizzardProfileClient::class);
        $client2->method('getCharacterPvpSummary')->willReturn(['brackets' => []]);
        $client2->method('getCharacterPvpBracketsChunked')->willReturn(['2v2' => []]);
        $mapper2 = $this->createMock(PvpBracketStatsMapper::class);
        $mapper2->method('map')->willReturnCallback(fn ($slug, $body) => $second[$slug] ?? null);
        $this->invoke('syncPvpData', $client2, $mapper2, $character);

        $this->assertSame(1, DB::table('character_pvp_brackets')->where('character_id', $character->id)->count());
        $this->assertDatabaseMissing('character_pvp_brackets', ['character_id' => $character->id, 'bracket' => '3v3']);
        $this->assertDatabaseHas('character_pvp_brackets', ['character_id' => $character->id, 'bracket' => '2v2', 'rating' => 1900, 'tier_name' => 'Challenger']);
    }
}
