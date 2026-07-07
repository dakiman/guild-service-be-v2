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
use App\Enums\SyncOrigin;
use App\Models\Character;
use App\Support\RaidRetention;
use Database\Seeders\GameDataExpansionSeeder;
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

    private function job(SyncOrigin $origin = SyncOrigin::UserLookup): SyncCharacterData
    {
        return new SyncCharacterData('eu', 'kazzak', 'tester', SyncDepth::Full, origin: $origin);
    }

    /**
     * @param  array<int, object>  $dtos
     */
    private function invoke(string $method, object $client, object $mapper, Character $character, SyncOrigin $origin = SyncOrigin::UserLookup): void
    {
        $ref = new \ReflectionMethod(SyncCharacterData::class, $method);
        $ref->setAccessible(true);
        $ref->invoke($this->job($origin), $client, $mapper, $character);
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

    public function test_background_origin_persists_only_retained_expansions(): void
    {
        $this->seed(GameDataExpansionSeeder::class);
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterRaidEncounters')->willReturn([]);

        $mapper = $this->createMock(RaidEncounterKillMapper::class);
        $mapper->method('map')->willReturn([
            new RaidEncounterKill('Midnight', 1300, 'Voidspire', 3001, 'Xareth', 'heroic', 4, 1700000000),
            // Distinct encounter_id: (character_id, encounter_id, difficulty)
            // is the upsert unique key, so a same-encounter duplicate would
            // collapse into one row instead of proving both names survive.
            new RaidEncounterKill(RaidRetention::CURRENT_SEASON, 1300, 'Voidspire', 3005, 'Season Boss', 'heroic', 4, 1700000000),
            new RaidEncounterKill('Legion', 8025, 'Nighthold', 1866, 'Gul-dan', 'mythic', 12, 1500000000),
        ]);
        $this->invoke('syncRaidEncounters', $client, $mapper, $character, SyncOrigin::Proactive);

        $this->assertSame(2, DB::table('raid_encounter_kills')->where('character_id', $character->id)->count());
        $this->assertDatabaseMissing('raid_encounter_kills', ['character_id' => $character->id, 'expansion_name' => 'Legion']);
    }

    public function test_background_origin_delete_missing_spares_legacy_rows(): void
    {
        $this->seed(GameDataExpansionSeeder::class);
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterRaidEncounters')->willReturn([]);

        // Pre-existing rows: one legacy (from an earlier user-lane sync),
        // one current-expansion row that the new payload no longer contains.
        DB::table('raid_encounter_kills')->insert([
            ['character_id' => $character->id, 'expansion_name' => 'Legion', 'instance_id' => 8025, 'instance_name' => 'Nighthold', 'encounter_id' => 1866, 'encounter_name' => 'Gul-dan', 'difficulty' => 'mythic', 'completed_count' => 12],
            ['character_id' => $character->id, 'expansion_name' => 'Midnight', 'instance_id' => 1300, 'instance_name' => 'Voidspire', 'encounter_id' => 3002, 'encounter_name' => 'Old Boss', 'difficulty' => 'heroic', 'completed_count' => 1],
        ]);

        $mapper = $this->createMock(RaidEncounterKillMapper::class);
        $mapper->method('map')->willReturn([
            new RaidEncounterKill('Midnight', 1300, 'Voidspire', 3001, 'Xareth', 'heroic', 4, 1700000000),
        ]);
        $this->invoke('syncRaidEncounters', $client, $mapper, $character, SyncOrigin::TeammateCrawl);

        // Legacy row untouched; stale current-expansion row pruned; new row written.
        $this->assertDatabaseHas('raid_encounter_kills', ['character_id' => $character->id, 'expansion_name' => 'Legion', 'encounter_id' => 1866]);
        $this->assertDatabaseMissing('raid_encounter_kills', ['character_id' => $character->id, 'encounter_id' => 3002]);
        $this->assertDatabaseHas('raid_encounter_kills', ['character_id' => $character->id, 'encounter_id' => 3001]);
    }

    public function test_user_lookup_origin_persists_all_expansions(): void
    {
        $this->seed(GameDataExpansionSeeder::class);
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterRaidEncounters')->willReturn([]);

        $mapper = $this->createMock(RaidEncounterKillMapper::class);
        $mapper->method('map')->willReturn([
            new RaidEncounterKill('Midnight', 1300, 'Voidspire', 3001, 'Xareth', 'heroic', 4, 1700000000),
            new RaidEncounterKill('Legion', 8025, 'Nighthold', 1866, 'Gul-dan', 'mythic', 12, 1500000000),
        ]);
        $this->invoke('syncRaidEncounters', $client, $mapper, $character, SyncOrigin::UserLookup);

        $this->assertSame(2, DB::table('raid_encounter_kills')->where('character_id', $character->id)->count());
        $this->assertDatabaseHas('raid_encounter_kills', ['character_id' => $character->id, 'expansion_name' => 'Legion']);
    }

    public function test_gating_fails_open_when_current_expansion_unknown(): void
    {
        // game_data_expansions NOT seeded -> RaidRetention::expansions() null.
        $character = Character::factory()->create();
        $client = $this->createStub(BlizzardProfileClient::class);
        $client->method('getCharacterRaidEncounters')->willReturn([]);

        $mapper = $this->createMock(RaidEncounterKillMapper::class);
        $mapper->method('map')->willReturn([
            new RaidEncounterKill('Legion', 8025, 'Nighthold', 1866, 'Gul-dan', 'mythic', 12, 1500000000),
        ]);
        $this->invoke('syncRaidEncounters', $client, $mapper, $character, SyncOrigin::Proactive);

        $this->assertDatabaseHas('raid_encounter_kills', ['character_id' => $character->id, 'expansion_name' => 'Legion']);
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
