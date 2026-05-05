<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\RaidEncounterKill;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RaidEncounterKill> */
class RaidEncounterKillFactory extends Factory
{
    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'expansion_name' => 'Midnight',
            'instance_id' => fake()->numberBetween(1000, 2000),
            'instance_name' => fake()->randomElement([
                'The Dreamrift', 'The Voidspire', 'March on Quel\'Danas',
            ]),
            'encounter_id' => fake()->unique()->numberBetween(3000, 9999),
            'encounter_name' => fake()->words(3, true),
            'difficulty' => fake()->randomElement(['Normal', 'Heroic', 'Mythic']),
            'completed_count' => fake()->numberBetween(1, 50),
            'last_kill_timestamp' => fake()->unixTime() * 1000,
        ];
    }
}
