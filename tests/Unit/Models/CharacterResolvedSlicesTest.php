<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Character;
use App\Models\GameDataExpansion;
use App\Models\GameDataFaction;
use App\Models\GameDataTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterResolvedSlicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_titles_resolves_names_from_game_data_ordered_by_id(): void
    {
        GameDataTitle::insert([
            ['id' => 205, 'name_male' => 'the Patient', 'name_female' => 'the Patient'],
            ['id' => 53, 'name_male' => 'Bloodsail Admiral', 'name_female' => 'Bloodsail Admiral'],
        ]);

        $character = Character::factory()->create(['title_ids' => [205, 53]]);

        $this->assertSame([
            ['id' => 53, 'name_male' => 'Bloodsail Admiral', 'name_female' => 'Bloodsail Admiral'],
            ['id' => 205, 'name_male' => 'the Patient', 'name_female' => 'the Patient'],
        ], $character->resolvedTitles());
    }

    public function test_resolved_titles_empty_for_null_and_empty(): void
    {
        $this->assertSame([], Character::factory()->create(['title_ids' => null])->resolvedTitles());
        $this->assertSame([], Character::factory()->create(['title_ids' => []])->resolvedTitles());
    }

    public function test_resolved_reputations_enriches_faction_block_when_game_data_exists(): void
    {
        $expansion = GameDataExpansion::query()->create(['id' => 505, 'name' => 'The War Within', 'display_order' => 10]);
        GameDataFaction::query()->create(['id' => 2570, 'name' => 'Hallowfall Arathi', 'parent_faction_id' => null, 'expansion_id' => $expansion->id]);

        $character = Character::factory()->create(['reputations' => [
            ['faction_id' => 2570, 'faction_name' => 'Hallowfall Arathi', 'standing' => 'revered', 'value' => 9000, 'max' => 21000],
            ['faction_id' => 999999, 'faction_name' => 'Unknown Faction', 'standing' => 'neutral', 'value' => 0, 'max' => 3000],
        ]]);

        $resolved = $character->resolvedReputations();

        $this->assertCount(2, $resolved);
        $this->assertSame(2570, $resolved[0]['faction_id']);
        $this->assertSame('Hallowfall Arathi', $resolved[0]['faction']['name']);
        $this->assertSame('The War Within', $resolved[0]['faction']['expansion']['name']);
        // No game-data row → faction key ABSENT (not null) — FE contract.
        $this->assertArrayNotHasKey('faction', $resolved[1]);
    }

    public function test_resolved_reputations_empty_for_null(): void
    {
        $this->assertSame([], Character::factory()->create(['reputations' => null])->resolvedReputations());
    }
}
